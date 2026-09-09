<?php
/**
 * lib_svg.php — Renderer de schémas d'exercices, style « plat » façon
 * entrainement-foot.fr : fond vert vif dégradé, zone de jeu en pointillé blanc,
 * pastilles pleines cerclées de blanc avec ombre portée douce, pas de surcharge
 * de marquages.
 *
 * Source unique de rendu, en PHP. Le SVG est du texte : il sert l'écran
 * (bibliothèque, générateur, mode terrain) et l'export PDF (image fixe), sans
 * dupliquer la logique côté JS.
 *
 * Entrée : la géométrie JSON d'un exercice (cahier des charges §6).
 *   {
 *     "zone":      { "type": "demi-terrain", "largeur": 50, "longueur": 34 },
 *     "joueurs":   [ { "x":10, "y":20, "equipe":"A", "label":"1" } ],
 *     "materiel":  [ { "type":"cone", "x":5, "y":5 } ],
 *     "mouvements":[ { "from":[10,20], "to":[25,20], "type":"passe" } ]
 *   }
 *
 * Options :
 *   'print'      true  -> traits et pastilles épaissis, image fixe pour l'A5
 *   'anime'      true  -> le ballon parcourt en boucle passes/conduites/tirs (SMIL)
 *   'largeur_px' force une largeur explicite (sinon 100 %)
 *   'courbure'   0..1  cintrage des flèches (défaut 0.16)
 *   'legende'    false pour masquer la légende
 *   'labels'     false pour masquer les numéros de joueurs
 */

declare(strict_types=1);

/* Palette. Fond vert, équipe A bleue, équipe B jaune, réserve blanche. */
const ET_SVG_VERT     = '#3CBE86';
const ET_SVG_VERT_2   = '#33B27C';
const ET_SVG_COL = [
    'A'       => ['fill' => '#2F80ED', 'text' => '#FFFFFF', 'stroke' => '#FFFFFF'],
    'B'       => ['fill' => '#F4C63D', 'text' => '#3A2E00', 'stroke' => '#FFFFFF'],
    'neutre'  => ['fill' => '#FFFFFF', 'text' => '#2B2B2B', 'stroke' => '#E4E7EA'],
    'gardien' => ['fill' => '#243B2C', 'text' => '#FFFFFF', 'stroke' => '#FFFFFF'],
];
const ET_SVG_MAT_COL = [
    'cone'     => '#F2994A',
    'coupelle' => '#EB5757',
    'ballon'   => '#FFFFFF',
    'plot'     => '#2F80ED',
];

/* Dimensions par défaut d'une zone (mètres) si la géométrie ne les donne pas. */
const ET_SVG_ZONES = [
    'terrain'    => [100, 64],
    'demi'       => [52, 40],
    'quart'      => [32, 26],
    'zone_libre' => [30, 24],
    'salle'      => [40, 22],
];

function et_svg_schema(array $geo, array $opt = []): string
{
    $print   = !empty($opt['print']);
    // Animé par défaut à l'écran : c'est le mouvement qui rend un exercice lisible.
    $anime   = !$print && (($opt['anime'] ?? true) !== false);
    $courbe  = isset($opt['courbure']) ? max(0.0, min(1.0, (float) $opt['courbure'])) : 0.16;
    $legende = ($opt['legende'] ?? true) !== false;
    $labels  = ($opt['labels'] ?? true) !== false;

    $k    = $print ? 12.0 : 9.5;    // pixels par mètre
    $pad  = $print ? 30.0 : 26.0;

    /* ---- Zone ---- */
    $zone = $geo['zone'] ?? [];
    $type = et_svg_type_zone((string) ($zone['type'] ?? 'quart'));
    [$defL, $defl] = ET_SVG_ZONES[$type] ?? ET_SVG_ZONES['quart'];
    $Lm = (float) ($zone['longueur'] ?? $zone['L'] ?? 0) ?: $defL;
    $lm = (float) ($zone['largeur']  ?? $zone['l'] ?? 0) ?: $defl;

    $sensBut = in_array($type, ['demi', 'quart'], true);
    if ($sensBut) {
        $pitchW = $lm * $k; $pitchH = $Lm * $k;
        $pt = fn($x, $y) => [$pad + (float) $y * $k, $pad + ($Lm - (float) $x) * $k];
    } else {
        $pitchW = $Lm * $k; $pitchH = $lm * $k;
        $pt = fn($x, $y) => [$pad + (float) $x * $k, $pad + (float) $y * $k];
    }
    $W = $pitchW + 2 * $pad;
    $H = $pitchH + 2 * $pad;
    $cxP = $pad + $pitchW / 2;
    $cyP = $pad + $pitchH / 2;

    $rj = $print ? 13 : 11;         // rayon pastille joueur
    $sw = $print ? 2.6 : 2.0;       // trait de base
    $wr = $print ? 3.4 : 2.8;       // liseré blanc des pastilles

    $present = [];
    $defs = [
        sprintf('<linearGradient id="etbg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="%s"/><stop offset="1" stop-color="%s"/></linearGradient>',
            ET_SVG_VERT, ET_SVG_VERT_2),
        '<radialGradient id="etgl" cx="0.28" cy="0.2" r="0.9">'
            . '<stop offset="0" stop-color="#ffffff" stop-opacity="0.16"/>'
            . '<stop offset="0.6" stop-color="#ffffff" stop-opacity="0"/></radialGradient>',
        '<filter id="etsh" x="-50%" y="-50%" width="200%" height="200%">'
            . '<feDropShadow dx="0" dy="2" stdDeviation="' . ($print ? 2 : 2.4) . '" flood-color="#0c3d27" flood-opacity="0.30"/></filter>',
    ];
    $body = [];

    /* ---------------------------------------------------------------- FOND */
    $body[] = sprintf('<rect x="0" y="0" width="%.0f" height="%.0f" rx="16" fill="url(#etbg)"/>', $W, $H);
    $body[] = sprintf('<rect x="0" y="0" width="%.0f" height="%.0f" rx="16" fill="url(#etgl)"/>', $W, $H);

    /* Zone de jeu : rectangle en pointillé blanc, plus quelques repères discrets. */
    $body[] = sprintf(
        '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4" fill="none" '
        . 'stroke="#FFFFFF" stroke-width="%.1f" stroke-dasharray="%s" stroke-opacity="0.92"/>',
        $pad, $pad, $pitchW, $pitchH, $sw + 0.4, $print ? '12 10' : '9 8'
    );
    $body[] = et_svg_reperes($type, $pad, $pitchW, $pitchH, $sw);

    /* ------------------------------------------------------------ MATÉRIEL */
    foreach ($geo['materiel'] ?? [] as $m) {
        $t = et_svg_slug((string) ($m['type'] ?? ''));
        if ($t === '') continue;
        $present['mat_' . $t] = et_svg_label_materiel($t);
        [$mx, $my] = $pt($m['x'] ?? 0, $m['y'] ?? 0);
        if (in_array($t, ['but', 'mini_but'], true) && ($sensBut || !isset($m['orientation']))) {
            $ori = et_svg_angle_vers($mx, $my, $cxP, $cyP);
        } else {
            $ori = (float) ($m['orientation'] ?? 0);
        }
        $body[] = et_svg_materiel($t, $mx, $my, $ori, $print);
    }

    /* ------------------------------------------------------------- JOUEURS
       Lus d'abord : positions nécessaires pour animer les acteurs et pour
       numéroter automatiquement les joueurs sans label. */
    $joueurs = [];
    $numEq = ['A' => 0, 'B' => 0, 'neutre' => 0, 'gardien' => 0];
    foreach ($geo['joueurs'] ?? [] as $j) {
        $eq = et_svg_equipe((string) ($j['equipe'] ?? ($j['role'] ?? '')));
        [$jx, $jy] = $pt($j['x'] ?? 0, $j['y'] ?? 0);
        $lbl = (string) ($j['label'] ?? '');
        if ($lbl === '' && $labels) $lbl = (string) (++$numEq[$eq]);
        $joueurs[] = ['x0' => $jx, 'y0' => $jy, 'eq' => $eq, 'label' => $lbl];
        $present['jr_' . $eq] = et_svg_label_equipe($eq);
    }
    // Décollage : on travaille sur x/y puis on recopie dans x0/y0 (origine des <g>).
    $tmp = array_map(fn($j) => ['x' => $j['x0'], 'y' => $j['y0']] + $j, $joueurs);
    et_svg_decoller_labels($tmp, $rj);
    foreach ($tmp as $i => $t) { $joueurs[$i]['x0'] = $t['x']; $joueurs[$i]['y0'] = $t['y']; }

    $pByLabel = [];
    foreach ($joueurs as $idx => $j) {
        $lk = strtolower((string) ($geo['joueurs'][$idx]['label'] ?? $j['label']));
        if ($lk !== '') $pByLabel[$lk] = $idx;
    }

    /* ---------------------------------------------------------- MOUVEMENTS */
    $mk = [];
    $moves = [];
    $maxOrdre = 0;
    $ordreExplicite = false;
    foreach ($geo['mouvements'] ?? [] as $idx => $mv) {
        $tmv = et_svg_slug((string) ($mv['type'] ?? 'course'));
        [$x1, $y1] = $pt(($mv['from'] ?? [0, 0])[0] ?? 0, ($mv['from'] ?? [0, 0])[1] ?? 0);
        [$x2, $y2] = $pt(($mv['to'] ?? [0, 0])[0] ?? 0, ($mv['to'] ?? [0, 0])[1] ?? 0);
        if (isset($mv['ordre'])) $ordreExplicite = true;
        $ordre = max(1, (int) ($mv['ordre'] ?? ($idx + 1)));
        $maxOrdre = max($maxOrdre, $ordre);
        $moves[] = ['t' => $tmv, 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2,
            'ordre' => $ordre, 'acteur' => strtolower((string) ($mv['acteur'] ?? '')), 'sens' => ($idx % 2) ? 1 : -1];
        $present['mv_' . $tmv] = et_svg_label_mouvement($tmv);
        $mk[$tmv] = true;
    }
    usort($moves, fn($a, $b) => $a['ordre'] <=> $b['ordre']);
    foreach (array_keys($mk) as $tm) $defs[] = et_svg_marker($tm);

    $stepDur  = 1.25;
    $total    = ($anime && $moves) ? $maxOrdre * $stepDur + 0.9 : 1.0;
    $sequence = $ordreExplicite || $maxOrdre > 1;

    // Flèches statiques (estompées si animation) + pastilles d'ordre
    $arrows = [];
    $badges = [];
    foreach ($moves as $m) {
        $arrows[] = et_svg_mouvement($m['t'], $m['x1'], $m['y1'], $m['x2'], $m['y2'], $courbe, $sw, $m['sens']);
        if ($sequence) {
            $ux = $m['x2'] - $m['x1']; $uy = $m['y2'] - $m['y1'];
            $l  = max(1.0, hypot($ux, $uy));
            $bx = $m['x1'] + $ux / $l * ($print ? 15 : 12);
            $by = $m['y1'] + $uy / $l * ($print ? 15 : 12);
            $badges[] = sprintf(
                '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#12321F" stroke="#FFFFFF" stroke-width="1.4"/>'
                . '<text x="%.1f" y="%.1f" font-family="Inter,Arial,sans-serif" font-size="%d" font-weight="700" '
                . 'fill="#FFFFFF" text-anchor="middle" dominant-baseline="central">%d</text>',
                $bx, $by, $print ? 8 : 7, $bx, $by + 0.4, $print ? 11 : 9.5, $m['ordre']
            );
        }
    }
    $body[] = $anime ? '<g opacity="0.42">' . implode('', $arrows) . '</g>' : implode('', $arrows);
    $body[] = implode('', $badges);

    // Segments par acteur (course / conduite) et pour le ballon (passe / conduite / tir)
    $segActeur = [];
    $segBalle  = [];
    foreach ($moves as $m) {
        $seg = ['sx' => $m['x1'], 'sy' => $m['y1'], 'ex' => $m['x2'], 'ey' => $m['y2'], 'ordre' => $m['ordre'], 'sens' => $m['sens']];
        if (in_array($m['t'], ['course', 'conduite'], true) && isset($pByLabel[$m['acteur']])) {
            $segActeur[$pByLabel[$m['acteur']]][] = $seg;
        }
        if (in_array($m['t'], ['passe', 'conduite', 'tir'], true)) $segBalle[] = $seg;
    }

    /* -------------------------------------------------------- RENDU JOUEURS */
    foreach ($joueurs as $idx => $j) {
        $c = ET_SVG_COL[$j['eq']];
        $inner = sprintf('<circle r="%.1f" fill="%s" stroke="%s" stroke-width="%.1f" filter="url(#etsh)"/>',
            $rj, $c['fill'], $c['stroke'], $wr);
        if ($labels && $j['label'] !== '') {
            $inner .= sprintf('<text y="0.5" font-family="Inter,Arial,sans-serif" font-size="%d" font-weight="700" '
                . 'fill="%s" text-anchor="middle" dominant-baseline="central">%s</text>',
                $print ? 13 : 11, $c['text'], et_svg_x($j['label']));
        }
        $anim = ($anime && !empty($segActeur[$idx]))
            ? et_svg_anim_motion([$j['x0'], $j['y0']], $segActeur[$idx], $stepDur, $total)
            : '';
        $body[] = sprintf('<g transform="translate(%.1f %.1f)">%s%s</g>', $j['x0'], $j['y0'], $inner, $anim);
    }

    /* Groupes de réserve (chasubles en attente), optionnels : geo.groupes[]. */
    foreach ($geo['groupes'] ?? [] as $g) {
        [$gx, $gy] = $pt($g['x'] ?? 0, $g['y'] ?? 0);
        $body[] = et_svg_groupe($gx, $gy, (int) ($g['nombre'] ?? 3), $print);
    }

    /* ---------------------------------------------------------- BALLON ANIMÉ */
    if ($anime && $segBalle) {
        $bx0 = $segBalle[0]['sx']; $by0 = $segBalle[0]['sy'];
        $body[] = sprintf(
            '<g transform="translate(%.1f %.1f)"><circle r="%.1f" fill="#FFFFFF" stroke="#1c1c1c" stroke-width="1.4" filter="url(#etsh)"/>%s</g>',
            $bx0, $by0, $print ? 6 : 5,
            et_svg_anim_motion([$bx0, $by0], $segBalle, $stepDur, $total)
        );
    }

    /* ------------------------------------------------------------- LÉGENDE */
    $legH = 0; $legSvg = '';
    if ($legende && $present) [$legSvg, $legH] = et_svg_legende($present, $W, $pad, $H, $print);

    $wAttr = !empty($opt['largeur_px']) ? sprintf('width="%d"', (int) $opt['largeur_px']) : 'width="100%"';
    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %.0f %.0f" %s '
        . 'preserveAspectRatio="xMidYMid meet" role="img" style="max-width:100%%;height:auto;display:block">'
        . '<defs>%s</defs>%s%s</svg>',
        $W, $H + $legH, $wAttr, implode('', $defs), implode('', $body), $legSvg
    );
}

/* ============================================================ SOUS-RENDUS */

function et_svg_type_zone(string $t): string
{
    $t = et_svg_slug($t);
    foreach (['demi', 'quart', 'zone_libre', 'salle', 'terrain'] as $k) {
        if (str_contains($t, $k)) return $k;
    }
    return 'quart';
}

/** Repères discrets dans la zone (volontairement minimalistes). */
function et_svg_reperes(string $type, float $p, float $w, float $h, float $sw): string
{
    $col = 'stroke="#FFFFFF" stroke-opacity="0.5" stroke-width="' . ($sw + 0.2) . '" fill="none"';
    $cx = $p + $w / 2; $cy = $p + $h / 2;
    if ($type === 'terrain') {
        return sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" %s/>'
            . '<circle cx="%.1f" cy="%.1f" r="%.1f" %s/>',
            $cx, $p, $cx, $p + $h, $col, $cx, $cy, min($w, $h) * 0.13, $col);
    }
    if ($type === 'demi') {
        $bw = min($w * 0.46, $h * 0.6); $bh = min($h * 0.3, $w * 0.26);
        return sprintf('<path d="M %.1f %.1f L %.1f %.1f L %.1f %.1f L %.1f %.1f" %s/>',
            $cx - $bw / 2, $p + $h, $cx - $bw / 2, $p + $h - $bh,
            $cx + $bw / 2, $p + $h - $bh, $cx + $bw / 2, $p + $h, $col);
    }
    return '';
}

function et_svg_materiel(string $t, float $x, float $y, float $ori, bool $print): string
{
    $s  = $print ? 1.25 : 1.0;
    $sw = $print ? 2.4 : 2.0;
    $dot = fn($col, $r) => sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" stroke="#FFFFFF" stroke-width="%.1f" filter="url(#etsh)"/>',
        $x, $y, $r * $s, $col, $print ? 2 : 1.6);
    switch ($t) {
        case 'cone':     return $dot(ET_SVG_MAT_COL['cone'], 4.6);
        case 'coupelle': return $dot(ET_SVG_MAT_COL['coupelle'], 4.2);
        case 'plot':     return $dot(ET_SVG_MAT_COL['plot'], 4.2);
        case 'ballon':
            return sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#FFFFFF" stroke="#1c1c1c" stroke-width="1.3" filter="url(#etsh)"/>',
                $x, $y, 4.6 * $s);
        case 'but':
        case 'mini_but':
            $gw = ($t === 'but' ? 26 : 15) * $s; $gd = 7 * $s;
            $rad = deg2rad($ori); $dx = cos($rad); $dy = sin($rad);
            $px = -$dy; $py = $dx;
            $ax = $x + $px * $gw / 2; $ay = $y + $py * $gw / 2;
            $bx = $x - $px * $gw / 2; $by = $y - $py * $gw / 2;
            return sprintf(
                '<g stroke="#FFFFFF" stroke-width="%.1f" fill="none" stroke-linecap="round" filter="url(#etsh)">'
                . '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>'
                . '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>'
                . '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/></g>',
                $sw + 1, $ax, $ay, $bx, $by,
                $ax, $ay, $ax + $dx * $gd, $ay + $dy * $gd,
                $bx, $by, $bx + $dx * $gd, $by + $dy * $gd
            );
        case 'mannequin':
            return sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#243B2C" stroke="#FFFFFF" stroke-width="%.1f" filter="url(#etsh)"/>',
                $x, $y, 6 * $s, $print ? 2.4 : 2);
        case 'haie':
            $hw = 9 * $s;
            return sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2" fill="#7B61FF" stroke="#FFFFFF" stroke-width="%.1f" filter="url(#etsh)"/>',
                $x - $hw / 2, $y - 3 * $s, $hw, 6 * $s, $print ? 2 : 1.6);
        case 'echelle':
            $lw = 11 * $s; $lh = 22 * $s; $rungs = '';
            for ($r = 1; $r < 5; $r++) {
                $ry = $y - $lh / 2 + $r * $lh / 5;
                $rungs .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>', $x - $lw / 2, $ry, $x + $lw / 2, $ry);
            }
            return sprintf('<g stroke="#FFFFFF" stroke-opacity="0.85" stroke-width="%.1f" fill="none">'
                . '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2"/>%s</g>',
                $sw, $x - $lw / 2, $y - $lh / 2, $lw, $lh, $rungs);
        default:
            return $dot('#EB5757', 4);
    }
}

function et_svg_groupe(float $x, float $y, int $n, bool $print): string
{
    $n = max(1, min(6, $n));
    $r = $print ? 6 : 5;
    $o = [];
    for ($i = 0; $i < $n; $i++) {
        $ang = $i / $n * 2 * M_PI;
        $o[] = sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#FFFFFF" stroke="#E4E7EA" stroke-width="1.4" filter="url(#etsh)"/>',
            $x + cos($ang) * $r * 1.15, $y + sin($ang) * $r * 1.15, $r);
    }
    return implode('', $o);
}

/**
 * <animateMotion> pour un élément (joueur ou ballon) qui parcourt une suite de
 * segments ordonnés, en boucle. L'élément est dessiné à l'origine d'un <g>
 * translaté en ($ox,$oy) ; le chemin est donc exprimé en coordonnées relatives.
 * keyTimes / keyPoints font que l'élément attend, se déplace pendant son
 * « ordre », puis attend de nouveau, calés sur la timeline globale.
 */
function et_svg_anim_motion(array $origin, array $segs, float $stepDur, float $total): string
{
    [$ox, $oy] = $origin;
    usort($segs, fn($a, $b) => $a['ordre'] <=> $b['ordre']);
    $K = count($segs);
    $curx = $ox; $cury = $oy;
    $path = 'M 0 0';
    $times = ['0']; $points = ['0'];
    $prevT1 = 0.0;
    foreach (array_values($segs) as $i => $s) {
        if (abs($s['sx'] - $curx) > 1 || abs($s['sy'] - $cury) > 1) {
            $path .= sprintf(' M %.1f %.1f', $s['sx'] - $ox, $s['sy'] - $oy);
        }
        $dx = $s['ex'] - $s['sx']; $dy = $s['ey'] - $s['sy'];
        $len = max(1.0, hypot($dx, $dy));
        $off = 0.16 * $len * 0.5 * ($s['sens'] ?? -1);
        $mx = ($s['sx'] + $s['ex']) / 2 + (-$dy / $len) * $off;
        $my = ($s['sy'] + $s['ey']) / 2 + ($dx / $len) * $off;
        $path .= sprintf(' Q %.1f %.1f %.1f %.1f', $mx - $ox, $my - $oy, $s['ex'] - $ox, $s['ey'] - $oy);
        $curx = $s['ex']; $cury = $s['ey'];

        $t0 = max($prevT1, ($s['ordre'] - 1) * $stepDur / $total);
        $t1 = max($t0 + 0.001, $s['ordre'] * $stepDur / $total);
        $prevT1 = $t1;
        $times[] = sprintf('%.4f', $t0);
        $times[] = sprintf('%.4f', min(1.0, $t1));
        $points[] = sprintf('%.4f', $i / $K);
        $points[] = sprintf('%.4f', ($i + 1) / $K);
    }
    $times[] = '1'; $points[] = '1';
    return sprintf(
        '<animateMotion dur="%.2fs" repeatCount="indefinite" rotate="0" calcMode="linear" keyPoints="%s" keyTimes="%s" path="%s"/>',
        $total, implode(';', $points), implode(';', $times), et_svg_x($path)
    );
}

function et_svg_seg_path(float $x1, float $y1, float $x2, float $y2, float $courbe, int $sens): string
{
    $mx = ($x1 + $x2) / 2; $my = ($y1 + $y2) / 2;
    $dx = $x2 - $x1; $dy = $y2 - $y1;
    $len = max(1.0, hypot($dx, $dy));
    $off = $courbe * $len * 0.5 * $sens;
    return sprintf('M %.1f %.1f Q %.1f %.1f %.1f %.1f',
        $x1, $y1, $mx + (-$dy / $len) * $off, $my + ($dx / $len) * $off, $x2, $y2);
}

function et_svg_mouvement(string $t, float $x1, float $y1, float $x2, float $y2, float $courbe, float $sw, int $sens): string
{
    $mx = ($x1 + $x2) / 2; $my = ($y1 + $y2) / 2;
    $dx = $x2 - $x1; $dy = $y2 - $y1;
    $len = max(1.0, hypot($dx, $dy));
    $off = $courbe * $len * 0.5 * $sens;
    $cxp = $mx + (-$dy / $len) * $off;
    $cyp = $my + ($dx / $len) * $off;
    $col = '#12321F';
    $mid = 'url(#etm_' . $t . ')';

    if ($t === 'conduite') {
        $steps = max(6, (int) ($len / 10));
        $d = sprintf('M %.1f %.1f', $x1, $y1);
        for ($i = 1; $i <= $steps; $i++) {
            $u = $i / $steps;
            $bx = (1 - $u) ** 2 * $x1 + 2 * (1 - $u) * $u * $cxp + $u * $u * $x2;
            $by = (1 - $u) ** 2 * $y1 + 2 * (1 - $u) * $u * $cyp + $u * $u * $y2;
            $amp = 3.0 * sin($u * M_PI * $steps / 1.5);
            $bx += (-$dy / $len) * $amp; $by += ($dx / $len) * $amp;
            $d .= sprintf(' L %.1f %.1f', $bx, $by);
        }
        return sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="%.1f" stroke-opacity="0.75" marker-end="%s"/>', $d, $col, $sw, $mid);
    }
    if ($t === 'tir') {
        $px = -$dy / $len * 2.2; $py = $dx / $len * 2.2;
        return sprintf(
            '<path d="M %.1f %.1f Q %.1f %.1f %.1f %.1f" fill="none" stroke="%s" stroke-width="%.1f" stroke-opacity="0.75"/>'
            . '<path d="M %.1f %.1f Q %.1f %.1f %.1f %.1f" fill="none" stroke="%s" stroke-width="%.1f" stroke-opacity="0.75" marker-end="%s"/>',
            $x1 + $px, $y1 + $py, $cxp + $px, $cyp + $py, $x2 + $px, $y2 + $py, $col, $sw,
            $x1 - $px, $y1 - $py, $cxp - $px, $cyp - $py, $x2 - $px, $y2 - $py, $col, $sw, $mid
        );
    }
    $dash = $t === 'passe' ? ' stroke-dasharray="7 5"' : '';
    return sprintf(
        '<path d="M %.1f %.1f Q %.1f %.1f %.1f %.1f" fill="none" stroke="%s" stroke-width="%.1f" stroke-opacity="0.78"%s marker-end="%s"/>',
        $x1, $y1, $cxp, $cyp, $x2, $y2, $col, $sw, $dash, $mid
    );
}

function et_svg_marker(string $t): string
{
    return sprintf('<marker id="etm_%s" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6.5" markerHeight="6.5" orient="auto-start-reverse">'
        . '<path d="M 0 0 L 10 5 L 0 10 z" fill="#12321F"/></marker>', $t);
}

function et_svg_decoller_labels(array &$joueurs, float $r): void
{
    $min = $r * 2.05; $n = count($joueurs);
    for ($p = 0; $p < 4; $p++) {
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $dx = $joueurs[$j]['x'] - $joueurs[$i]['x'];
                $dy = $joueurs[$j]['y'] - $joueurs[$i]['y'];
                $d  = hypot($dx, $dy);
                if ($d > 0.01 && $d < $min) {
                    $push = ($min - $d) / 2 + 0.5;
                    $ux = $dx / $d; $uy = $dy / $d;
                    $joueurs[$i]['x'] -= $ux * $push; $joueurs[$i]['y'] -= $uy * $push;
                    $joueurs[$j]['x'] += $ux * $push; $joueurs[$j]['y'] += $uy * $push;
                }
            }
        }
    }
}

function et_svg_legende(array $present, float $W, float $pad, float $H, bool $print): array
{
    $fs = $print ? 12 : 10.5;
    $rowH = $print ? 20 : 17;
    $colW = $print ? 150 : 128;
    $cols = max(1, (int) floor(($W - 2 * $pad) / $colW));
    $items = array_values($present);
    $rows = (int) ceil(count($items) / $cols);
    $legH = $rows * $rowH + 14;
    $y0 = $H + 6;
    $g = [];
    foreach ($items as $i => $txt) {
        $cx = $pad + ($i % $cols) * $colW;
        $cy = $y0 + (int) floor($i / $cols) * $rowH + $rowH / 2;
        $g[] = sprintf('<circle cx="%.1f" cy="%.1f" r="4" fill="%s"/>', $cx + 6, $cy, ET_SVG_VERT);
        $g[] = sprintf('<text x="%.1f" y="%.1f" font-family="Inter,Arial,sans-serif" font-size="%.1f" fill="#5A5A52" dominant-baseline="central">%s</text>',
            $cx + 16, $cy, $fs, et_svg_x($txt));
    }
    return [implode('', $g), $legH];
}

/* ================================================================ HELPERS */

function et_svg_angle_vers(float $x, float $y, float $cx, float $cy): float
{
    return round(rad2deg(atan2($cy - $y, $cx - $x)) / 90) * 90;
}

function et_svg_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = strtr($s, ['-' => '_', ' ' => '_', 'é' => 'e', 'è' => 'e', 'ê' => 'e']);
    return preg_replace('/[^a-z0-9_]/', '', $s) ?? '';
}

function et_svg_equipe(string $s): string
{
    $s = strtolower(trim($s));
    if ($s === 'a') return 'A';
    if ($s === 'b') return 'B';
    if (str_contains($s, 'gard') || $s === 'gk' || str_contains($s, 'keeper')) return 'gardien';
    return 'neutre';
}

function et_svg_x(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function et_svg_label_equipe(string $eq): string
{
    return ['A' => 'Équipe A', 'B' => 'Équipe B', 'neutre' => 'Joueur neutre', 'gardien' => 'Gardien'][$eq] ?? $eq;
}

function et_svg_label_materiel(string $t): string
{
    return [
        'cone' => 'Cône', 'coupelle' => 'Coupelle', 'plot' => 'Plot', 'but' => 'But', 'mini_but' => 'Mini-but',
        'mannequin' => 'Mannequin', 'haie' => 'Haie', 'echelle' => 'Échelle', 'ballon' => 'Ballon',
    ][$t] ?? ucfirst($t);
}

function et_svg_label_mouvement(string $t): string
{
    return ['course' => 'Course', 'passe' => 'Passe', 'conduite' => 'Conduite', 'tir' => 'Tir'][$t] ?? ucfirst($t);
}
