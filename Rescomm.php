<?php
/**
 * ResComm - Residue Interaction Network Analyzer
 * v2: user-configurable interaction distance thresholds
 *
 * Literature-derived DEFAULT thresholds (Ca-Ca unless noted). These remain
 * the out-of-the-box values and are what the validation results in the
 * manuscript were computed with. Users may now override any of them from
 * the upload form (or via POST parameters) to test sensitivity on their
 * own structure, as recommended by the editor and consistent with the
 * practice of comparable tools (PIC: Tina et al. 2007; ProtInter: Borry &
 * Schmidt 2025).
 *
 *   - H-bond threshold:    3.5 A  (McDonald & Thornton 1994)
 *   - Salt bridge:         4.5 A  (Kumar & Nussinov 1999)
 *   - Hydrophobic:         6.0 A  (Tsai et al. 1997)
 *   - Disulfide (SG-SG):   2.56 A (Thornton 1981)
 *   - General contact:     8.0 A  (Brinda & Vishveshwara 2005)
 *   - LPA max iterations:  50
 *   - BFS fallback:        implemented for networks with <100 edges
 *   - Betweenness:         full Brandes always
 *   - Singleton communities: filtered, disclosed in report
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '2048M');
set_time_limit(300);

foreach (['uploads', 'output', 'temp'] as $dir)
    if (!file_exists($dir)) mkdir($dir, 0777, true);

$action      = $_GET['action'] ?? 'form';
$analysis_id = $_GET['id']     ?? '';

// -- Progress polling --------------------------------------------------------
if ($action === 'progress') {
    header('Content-Type: application/json');
    $pid  = $_GET['pid'] ?? '';
    $file = "temp/progress_$pid.txt";
    echo file_exists($file)
        ? json_encode(['progress' => trim(file_get_contents($file))])
        : json_encode(['progress' => 0]);
    exit;
}

switch ($action) {
    case 'analyze':            handleAnalysis();           break;
    case 'analyze_background': handleBackgroundAnalysis(); break;
    case 'view':               viewAnalysis($analysis_id); break;
    case 'download':           downloadFile();             break;
    default:                   showUploadForm();           break;
}

/**
 * Default, literature-calibrated thresholds. Exposed as a free function so
 * both the form (for pre-filling/labelling) and the analysis handlers share
 * a single source of truth.
 */
function defaultThresholds(): array {
    return [
        'hydrogen_bond' => 3.5,
        'salt_bridge'   => 4.5,
        'hydrophobic'   => 6.0,
        'disulfide_sg'  => 2.56,
        'disulfide_ca'  => 6.5,
        'contact'       => 8.0,
    ];
}

/**
 * Sane min/max bounds for each user-adjustable threshold, used both for the
 * HTML number-input constraints and for server-side validation. Bounds are
 * generous enough to support sensitivity testing while preventing
 * pathological inputs (e.g. a 0 A or 500 A cutoff) that would make the
 * O(n*k) grid optimisation incorrect or the run effectively unbounded.
 */
function thresholdBounds(): array {
    return [
        'hydrogen_bond' => [2.5, 5.0],
        'salt_bridge'   => [3.0, 8.0],
        'hydrophobic'   => [3.0, 10.0],
        'disulfide_sg'  => [1.8, 3.5],
        'contact'       => [5.0, 12.0],
    ];
}

/** Read and validate threshold overrides from request input (POST or GET). */
function readThresholdOverrides(array $src): array {
    $defaults = defaultThresholds();
    $bounds   = thresholdBounds();
    $out      = $defaults;
    foreach ($bounds as $key => [$min, $max]) {
        if (isset($src[$key]) && is_numeric($src[$key])) {
            $v = (float)$src[$key];
            $out[$key] = max($min, min($max, $v));
        }
    }
    // disulfide_ca fallback is not user-facing; keep literature default
    $out['disulfide_ca'] = $defaults['disulfide_ca'];
    return $out;
}

// -- SVG -> PNG via PHP GD ----------------------------------------------------
function svgToPng(string $svgPath, string $pngPath, int $width = 1200): bool {
    if (!file_exists($svgPath)) return false;
    $svg = file_get_contents($svgPath);
    if (empty($svg))            return false;

    if (preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $m)) {
        $ow = (int)$m[1]; $oh = (int)$m[2];
    } elseif (preg_match('/width="(\d+)".*?height="(\d+)"/s', $svg, $m)) {
        $ow = (int)$m[1]; $oh = (int)$m[2];
    } else {
        $ow = 1000; $oh = 800;
    }

    $scale = $width / $ow;
    $height = (int)($oh * $scale);

    $img   = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($img, 248, 249, 250);
    imagefill($img, 0, 0, $white);

    // Draw edges (lines)
    if (preg_match_all('/<line[^>]*x1="([^"]*)"[^>]*y1="([^"]*)"[^>]*x2="([^"]*)"[^>]*y2="([^"]*)"[^>]*stroke="([^"]*)"[^>]*stroke-width="([^"]*)"[^>]*\/?>/i',
            $svg, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $l) {
            $x1 = (int)($l[1]*$scale); $y1 = (int)($l[2]*$scale);
            $x2 = (int)($l[3]*$scale); $y2 = (int)($l[4]*$scale);
            if (preg_match('/#([A-Fa-f0-9]{6})/', $l[5], $cm)) {
                $c = imagecolorallocate($img,
                    hexdec(substr($cm[1],0,2)),
                    hexdec(substr($cm[1],2,2)),
                    hexdec(substr($cm[1],4,2)));
                $sw = max(1, (int)($l[6]*$scale));
                for ($i = 0; $i < $sw; $i++) {
                    imageline($img, $x1, $y1+$i, $x2, $y2+$i, $c);
                    imageline($img, $x1+$i, $y1, $x2+$i, $y2, $c);
                }
                imageline($img, $x1, $y1, $x2, $y2, $c);
            }
        }
    }

    // Draw nodes (circles)
    if (preg_match_all('/<circle[^>]*cx="([^"]*)"[^>]*cy="([^"]*)"[^>]*r="([^"]*)"[^>]*fill="([^"]*)"[^>]*\/?>/i',
            $svg, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $ci) {
            $cx = (int)($ci[1]*$scale); $cy = (int)($ci[2]*$scale);
            $r  = (int)max(1, $ci[3]*$scale);
            if (preg_match('/#([A-Fa-f0-9]{6})/', $ci[4], $cm)) {
                $rgb = [hexdec(substr($cm[1],0,2)), hexdec(substr($cm[1],2,2)), hexdec(substr($cm[1],4,2))];
                $fill = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
                $border = imagecolorallocate($img, max(0,$rgb[0]-50), max(0,$rgb[1]-50), max(0,$rgb[2]-50));
                imagefilledellipse($img, $cx, $cy, $r*2, $r*2, $fill);
                imageellipse($img, $cx, $cy, $r*2, $r*2, $border);
            }
        }
    }

    // Draw text labels
    if (preg_match_all('/<text[^>]*x="([^"]*)"[^>]*y="([^"]*)"[^>]*class="label"[^>]*>([^<]*)<\/text>/i',
            $svg, $matches, PREG_SET_ORDER)) {
        $tc = imagecolorallocate($img, 51, 51, 51);
        foreach ($matches as $t)
            imagestring($img, 3, (int)($t[1]*$scale)-10, (int)($t[2]*$scale)-8, trim($t[3]), $tc);
    }

    imagepng($img, $pngPath, 9);
    imagedestroy($img);
    return file_exists($pngPath);
}

// ----------------------------------------------------------------------------
// ResComm Core Engine
// ----------------------------------------------------------------------------
class ResComm {

    private string $pdbFile;
    private array  $residues     = [];
    private array  $interactions = [];
    private array  $graph        = [];
    private array  $communities  = [];
    private int    $singletonCount = 0;   // disclosed in report
    private float  $modularity   = 0.0;
    private float  $lpaStability = 0.0;
    private array  $stats        = [];
    private string $progressId;

    /** User-adjustable distance thresholds. Populated in the constructor. */
    private array $thresholds;

    /** Whether every threshold equals the literature default (reported to the user). */
    private bool $usingDefaultThresholds;

    // -- Standard amino acids ------------------------------------------------
    private const STANDARD_AA = [
        'ALA','ARG','ASN','ASP','CYS','GLN','GLU','GLY','HIS','ILE',
        'LEU','LYS','MET','PHE','PRO','SER','THR','TRP','TYR','VAL'
    ];

    // -- Non-protein entities to filter --------------------------------------
    private const NON_PROTEIN = [
        'HEM','SO4','OXY','NAG','MAN','GLC','FUC','IPA','EDO','PEG',
        'GOL','ACT','PO4','MES','IMD','CIT','ACE','NH2','UNK'
    ];

    // -- Water residue names (Supplementary Table S1) ------------------------
    private const WATER_NAMES = [
        'HOH','H2O','WAT','DOD','OH2','SOL','TIP','TIP3','TP3','TP4','TP5'
    ];

    // -- H-bond donor/acceptor sets -------------------------------------------
    private const HB_DONORS    = ['ARG','ASN','GLN','HIS','LYS','SER','THR','TRP','TYR'];
    private const HB_ACCEPTORS = ['ASP','GLU','ASN','GLN','HIS','SER','THR','TYR'];

    // -- Salt bridge charged sets ---------------------------------------------
    private const SB_POS = ['ARG','LYS','HIS'];
    private const SB_NEG = ['ASP','GLU'];

    // -- Hydrophobic residues -------------------------------------------------
    private const HYDROPHOBIC = ['ALA','VAL','ILE','LEU','MET','PHE','PRO','TRP','CYS','TYR'];

    // -- Edge base weights ----------------------------------------------------
    private const BASE_WEIGHTS = [
        'disulfide'     => 4.0,
        'hydrogen_bond' => 3.0,
        'salt_bridge'   => 2.5,
        'hydrophobic'   => 1.5,
        'contact'       => 1.0,
    ];

    /**
     * @param array $thresholdOverrides Optional map of threshold name => value
     *   (any subset of 'hydrogen_bond','salt_bridge','hydrophobic',
     *   'disulfide_sg','contact'). Missing keys fall back to the
     *   literature-calibrated defaults in defaultThresholds(). Values are
     *   NOT re-validated here — callers (readThresholdOverrides()) are
     *   responsible for bounds-checking user input before it reaches this
     *   constructor.
     */
    public function __construct(string $pdbPath, string $progressId = '', array $thresholdOverrides = []) {
        $this->pdbFile    = $pdbPath;
        $this->progressId = $progressId;
        $this->thresholds = array_merge(defaultThresholds(), $thresholdOverrides);
        $this->usingDefaultThresholds = ($this->thresholds === defaultThresholds());
        $this->parsePDB();
    }

    public function getThresholds(): array { return $this->thresholds; }
    public function isUsingDefaultThresholds(): bool { return $this->usingDefaultThresholds; }

    // -- Progress helper ------------------------------------------------------
    private function progress(string $stage, int $pct): void {
        if ($this->progressId)
            file_put_contents("temp/progress_{$this->progressId}.txt", "$stage:$pct");
    }

    // ------------------------------------------------------------------------
    // 1. PDB PARSING
    // ------------------------------------------------------------------------
    private function parsePDB(): void {
        $this->progress('parsing', 10);

        $content = file_get_contents($this->pdbFile);
        if (!$content) throw new \RuntimeException("Cannot read PDB file.");

        $lines         = explode("\n", $content);
        $residueAtoms  = [];
        $waterCount    = 0;
        $filteredCount = 0;
        $atomCount     = 0;
        $filterNames   = array_merge(self::WATER_NAMES, self::NON_PROTEIN);

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (strlen($line) < 1) continue;

            $recType = trim(substr($line, 0, 6));
            if ($recType !== 'ATOM' && $recType !== 'HETATM') continue;

            $atomCount++;
            $atomName = trim(substr($line, 12, 4));
            $resName  = strlen($line) >= 20 ? trim(substr($line, 17, 3)) : '';
            if (empty($resName) || strlen($resName) < 2) continue;

            if (in_array($resName, $filterNames, true)) {
                if (in_array($resName, self::WATER_NAMES, true)) $waterCount++;
                else $filteredCount++;
                continue;
            }

            // Chain ID
            $chain = 'A';
            if (strlen($line) >= 22) {
                $c = substr($line, 21, 1);
                if ($c !== ' ' && ctype_alpha($c)) $chain = $c;
            }

            // Residue sequence number
            $resSeq = 0;
            if (strlen($line) >= 26) {
                preg_match('/\d+/', substr($line, 22, 4), $rm);
                $resSeq = !empty($rm[0]) ? (int)$rm[0] : (int)trim(substr($line, 22, 4));
            }
            if ($resSeq <= 0) continue;

            // Coordinates
            if (strlen($line) < 54) continue;
            $xs = trim(substr($line, 30, 8));
            $ys = trim(substr($line, 38, 8));
            $zs = trim(substr($line, 46, 8));
            if (!is_numeric($xs) || !is_numeric($ys) || !is_numeric($zs)) continue;

            $atom = [
                'name'    => $atomName,
                'resName' => $resName,
                'chain'   => $chain,
                'resSeq'  => $resSeq,
                'x'       => (float)$xs,
                'y'       => (float)$ys,
                'z'       => (float)$zs,
            ];

            $key = "{$chain}_{$resSeq}_{$resName}";
            if (!isset($residueAtoms[$key]))
                $residueAtoms[$key] = ['resName'=>$resName,'chain'=>$chain,'resSeq'=>$resSeq,
                                       'atoms'=>[],'ca'=>null];

            $residueAtoms[$key]['atoms'][] = $atom;
            if (strtoupper(trim($atomName)) === 'CA')
                $residueAtoms[$key]['ca'] = $atom;
        }

        if (empty($residueAtoms))
            throw new \RuntimeException(
                "No valid protein residues found. "
                ."Filtered: {$waterCount} water, {$filteredCount} non-protein, {$atomCount} total ATOM/HETATM lines.");

        $nonStd = 0;
        foreach ($residueAtoms as $key => $data) {
            if (!in_array($data['resName'], self::STANDARD_AA, true)) $nonStd++;
            $this->residues[$key] = [
                'resName' => $data['resName'],
                'chain'   => $data['chain'],
                'resSeq'  => $data['resSeq'],
                'center'  => $this->calcCenter($data),
                'atoms'   => $data['atoms'],
            ];
        }

        $this->stats = [
            'total_lines'           => count($lines),
            'water_molecules'       => $waterCount,
            'filtered_entities'     => $filteredCount,
            'non_standard_residues' => $nonStd,
            'protein_residues'      => count($residueAtoms),
        ];

        $this->progress('parsing', 100);
    }

    /**
     * Residue centre: Ca preferred, then key side-chain atoms, then geometric mean.
     * Using Ca keeps computation efficient while accurately representing residue position.
     */
    private function calcCenter(array $d): array {
        if ($d['ca'])
            return ['x'=>$d['ca']['x'], 'y'=>$d['ca']['y'], 'z'=>$d['ca']['z']];

        $sc = $this->sideChainAtoms($d['resName'], $d['atoms']);
        $pool = empty($sc) ? $d['atoms'] : $sc;

        $sx = $sy = $sz = 0.0;
        foreach ($pool as $a) { $sx += $a['x']; $sy += $a['y']; $sz += $a['z']; }
        $n = count($pool);
        return ['x'=>$sx/$n, 'y'=>$sy/$n, 'z'=>$sz/$n];
    }

    private function sideChainAtoms(string $res, array $atoms): array {
        static $defs = [
            'ARG'=>['NE','CZ','NH1','NH2'], 'LYS'=>['NZ'],
            'ASP'=>['CG','OD1','OD2'],      'GLU'=>['CD','OE1','OE2'],
            'ASN'=>['CG','OD1','ND2'],      'GLN'=>['CD','OE1','NE2'],
            'SER'=>['OG'],                  'THR'=>['OG1'],
            'TYR'=>['CG','CD1','CD2','CE1','CE2','CZ','OH'],
            'PHE'=>['CG','CD1','CD2','CE1','CE2','CZ'],
            'TRP'=>['CG','CD1','CD2','NE1','CE2','CE3','CZ2','CZ3','CH2'],
            'HIS'=>['CG','ND1','CD2','CE1','NE2'],
            'MET'=>['CG','SD','CE'], 'CYS'=>['SG'],
        ];
        $target = $defs[$res] ?? [];
        if (empty($target)) return [];
        return array_values(array_filter($atoms, fn($a) => in_array(trim($a['name']), $target, true)));
    }

    public function getStats(): array { return $this->stats; }

    // ------------------------------------------------------------------------
    // 2. INTERACTION DETECTION
    // ------------------------------------------------------------------------
    public function detectInteractions(): array {
        $this->progress('interactions', 10);
        $keys = array_keys($this->residues);
        $n    = count($keys);
        if ($n === 0) { $this->progress('interactions', 100); return []; }

        // Disulfide bonds require SG-SG atom-level distance (Thornton 1981)
        $this->detectDisulfideBonds($keys);

        if ($n > 300)
            $this->detectInteractionsGrid($keys);
        else
            $this->detectInteractionsNaive($keys);

        $this->progress('interactions', 100);
        return $this->interactions;
    }

    private function detectInteractionsNaive(array $keys): void {
        $n    = count($keys);
        $step = max(1, (int)floor($n / 100));
        $contactMax = $this->thresholds['contact'];
        for ($i = 0; $i < $n; $i++) {
            if ($i % $step === 0) $this->progress('interactions', 10 + (int)(($i/$n)*80));
            $r1 = $this->residues[$keys[$i]];
            for ($j = $i+1; $j < $n; $j++) {
                $r2 = $this->residues[$keys[$j]];
                if ($this->sequentiallyClose($r1, $r2)) continue;
                $dist = $this->dist($r1['center'], $r2['center']);
                if ($dist <= $contactMax) {
                    $type = $this->classifyInteraction($r1, $r2, $dist);
                    if ($type && $type !== 'disulfide')
                        $this->addInteraction($keys[$i], $keys[$j], $type, $dist);
                }
            }
        }
    }

    /**
     * Grid-based O(n.k) detection for proteins > 300 residues.
     * Cell size equals the (possibly user-adjusted) contact threshold, so
     * only adjacent cells need checking regardless of the chosen cutoff.
     */
    private function detectInteractionsGrid(array $keys): void {
        $gs   = $this->thresholds['contact'];
        $grid = [];
        foreach ($keys as $key) {
            $r = $this->residues[$key];
            $gk = floor($r['center']['x']/$gs).','
                 .floor($r['center']['y']/$gs).','
                 .floor($r['center']['z']/$gs);
            $grid[$gk][] = $key;
        }

        $total = count($grid); $done = 0;
        foreach ($grid as $gk => $cellKeys) {
            $done++;
            if ($done % 10 === 0) $this->progress('interactions', 10 + (int)(($done/$total)*80));
            [$gx,$gy,$gz] = array_map('intval', explode(',', $gk));
            for ($dx=-1;$dx<=1;$dx++) for ($dy=-1;$dy<=1;$dy++) for ($dz=-1;$dz<=1;$dz++) {
                $nk = ($gx+$dx).','.($gy+$dy).','.($gz+$dz);
                if (isset($grid[$nk]))
                    $this->checkPairs($cellKeys, $grid[$nk]);
            }
        }
    }

    private function checkPairs(array $k1, array $k2): void {
        $contactMax = $this->thresholds['contact'];
        foreach ($k1 as $a) {
            $ra = $this->residues[$a];
            foreach ($k2 as $b) {
                if ($a >= $b) continue;
                $rb = $this->residues[$b];
                if ($this->sequentiallyClose($ra, $rb)) continue;
                $dist = $this->dist($ra['center'], $rb['center']);
                if ($dist <= $contactMax) {
                    $type = $this->classifyInteraction($ra, $rb, $dist);
                    if ($type) $this->addInteraction($a, $b, $type, $dist);
                    if (count($this->interactions) >= 5000) return;
                }
            }
        }
    }

    /** Skip sequentially adjacent residues on the same chain (< 3 apart). */
    private function sequentiallyClose(array $r1, array $r2): bool {
        return $r1['chain'] === $r2['chain'] && abs($r1['resSeq'] - $r2['resSeq']) < 3;
    }

    private function addInteraction(string $k1, string $k2, string $type, float $dist): void {
        $this->interactions[] = [
            'res1'     => $k1,
            'res2'     => $k2,
            'type'     => $type,
            'distance' => $dist,
            'weight'   => $this->calcWeight($type, $dist),
        ];
    }

    /**
     * Disulfide detection uses SG-SG distance (default 2.56 A, Thornton
     * 1981; user-adjustable). Falls back to Ca-Ca (6.5 A, fixed) when SG
     * coordinates are absent.
     */
    private function detectDisulfideBonds(array $keys): void {
        $cysList = array_values(array_filter($keys, fn($k) => $this->residues[$k]['resName'] === 'CYS'));
        $nc = count($cysList);
        $sgMax = $this->thresholds['disulfide_sg'];
        $caMax = $this->thresholds['disulfide_ca'];
        for ($i = 0; $i < $nc; $i++) {
            for ($j = $i+1; $j < $nc; $j++) {
                $r1 = $this->residues[$cysList[$i]];
                $r2 = $this->residues[$cysList[$j]];
                if ($this->sequentiallyClose($r1, $r2)) continue;

                $sg1 = $this->getAtomCoords($r1['atoms'], 'SG');
                $sg2 = $this->getAtomCoords($r2['atoms'], 'SG');

                if ($sg1 && $sg2) {
                    if ($this->dist($sg1, $sg2) <= $sgMax)
                        $this->addInteraction($cysList[$i], $cysList[$j], 'disulfide', $this->dist($sg1, $sg2));
                } else {
                    $caDist = $this->dist($r1['center'], $r2['center']);
                    if ($caDist <= $caMax)
                        $this->addInteraction($cysList[$i], $cysList[$j], 'disulfide', $caDist);
                }
            }
        }
    }

    private function getAtomCoords(array $atoms, string $name): ?array {
        foreach ($atoms as $a)
            if (trim($a['name']) === $name)
                return ['x'=>$a['x'], 'y'=>$a['y'], 'z'=>$a['z']];
        return null;
    }

    /**
     * Classify a residue pair by interaction type, using the (possibly
     * user-adjusted) thresholds held in $this->thresholds.
     */
    private function classifyInteraction(array $r1, array $r2, float $dist): string|false {
        $a = $r1['resName']; $b = $r2['resName'];

        // CYS-CYS pairs are handled separately as disulfide bonds
        if ($a === 'CYS' && $b === 'CYS') return false;

        if ($dist <= $this->thresholds['hydrogen_bond']) {
            if ((in_array($a, self::HB_DONORS, true) && in_array($b, self::HB_ACCEPTORS, true)) ||
                (in_array($b, self::HB_DONORS, true) && in_array($a, self::HB_ACCEPTORS, true)))
                return 'hydrogen_bond';
        }

        if ($dist <= $this->thresholds['salt_bridge']) {
            if ((in_array($a, self::SB_POS, true) && in_array($b, self::SB_NEG, true)) ||
                (in_array($b, self::SB_POS, true) && in_array($a, self::SB_NEG, true)))
                return 'salt_bridge';
        }

        if ($dist <= $this->thresholds['hydrophobic']) {
            if (in_array($a, self::HYDROPHOBIC, true) && in_array($b, self::HYDROPHOBIC, true))
                return 'hydrophobic';
        }

        if ($dist <= $this->thresholds['contact']) return 'contact';

        return false;
    }

    /**
     * Edge weight = base_weight x max(0.1, 1 - distance/10).
     * Disulfide bonds are covalent and receive flat weight 4.0.
     */
    private function calcWeight(string $type, float $dist): float {
        $base = self::BASE_WEIGHTS[$type] ?? 1.0;
        return $type === 'disulfide' ? $base : $base * max(0.1, 1.0 - ($dist / 10.0));
    }

    // ------------------------------------------------------------------------
    // 3. GRAPH CONSTRUCTION
    // ------------------------------------------------------------------------
    public function buildGraph(): array {
        $this->progress('graph', 10);
        $this->graph = ['nodes' => [], 'edges' => []];

        foreach ($this->residues as $key => $res)
            $this->graph['nodes'][$key] = [
                'id'          => $key,
                'label'       => $res['resName'].$res['resSeq'].$res['chain'],
                'resName'     => $res['resName'],
                'resSeq'      => $res['resSeq'],
                'chain'       => $res['chain'],
                'degree'      => 0,
                'strength'    => 0.0,
                'betweenness' => 0.0,
                'hub_score'   => 0.0,
            ];

        $this->progress('graph', 50);

        foreach ($this->interactions as $ix) {
            $this->graph['edges'][] = [
                'source' => $ix['res1'],
                'target' => $ix['res2'],
                'type'   => $ix['type'],
                'weight' => $ix['weight'],
            ];
            $this->graph['nodes'][$ix['res1']]['degree']++;
            $this->graph['nodes'][$ix['res2']]['degree']++;
            $this->graph['nodes'][$ix['res1']]['strength'] += $ix['weight'];
            $this->graph['nodes'][$ix['res2']]['strength'] += $ix['weight'];
        }

        $this->progress('graph', 100);
        return $this->graph;
    }

    // ------------------------------------------------------------------------
    // 4. COMMUNITY DETECTION  (Weighted Label Propagation Algorithm)
    // ------------------------------------------------------------------------
    public function detectCommunities(): array {
        $this->progress('communities', 10);

        $edgeCount = count($this->graph['edges']);

        if ($edgeCount < 100) {
            $this->progress('communities', 80);
            $partition = $this->connectedComponentsBFS();
        } else {
            $best = null; $bestQ = -INF;
            for ($run = 0; $run < 10; $run++) {
                $p = $this->weightedLPA();
                $q = $this->calculateModularity($p);
                if ($q > $bestQ) { $bestQ = $q; $best = $p; }
            }
            $this->modularity  = $bestQ;
            $this->lpaStability = $this->partitionStability(
                array_map(fn() => $this->weightedLPA(), range(0, 9))
            );
            $partition = $best;
        }

        $raw = [];
        foreach ($partition as $node => $label) $raw[$label][] = $node;

        $this->singletonCount = 0;
        $filtered = [];
        foreach ($raw as $members) {
            if (count($members) === 1) { $this->singletonCount++; continue; }
            $filtered[] = $members;
        }
        $this->communities = array_values($filtered);

        $this->progress('communities', 100);
        return $this->communities;
    }

    private function weightedLPA(): array {
        $nodes  = array_keys($this->graph['nodes']);
        $labels = [];
        foreach ($nodes as $i => $n) $labels[$n] = $i;

        for ($iter = 0; $iter < 50; $iter++) {
            $changed = false;
            shuffle($nodes);
            foreach ($nodes as $node) {
                $votes = [];
                foreach ($this->graph['edges'] as $e) {
                    if      ($e['source'] === $node) { $nb = $e['target']; $w = $e['weight']; }
                    elseif  ($e['target'] === $node) { $nb = $e['source']; $w = $e['weight']; }
                    else continue;
                    $lbl = $labels[$nb];
                    $votes[$lbl] = ($votes[$lbl] ?? 0.0) + $w;
                }
                if (!empty($votes)) {
                    arsort($votes);
                    $newLabel = key($votes);
                    if ($newLabel !== $labels[$node]) { $labels[$node] = $newLabel; $changed = true; }
                }
            }
            if (!$changed) break;
        }

        $map = []; $id = 0;
        foreach (array_unique($labels) as $l) $map[$l] = $id++;
        return array_map(fn($l) => $map[$l], $labels);
    }

    private function connectedComponentsBFS(): array {
        $adj = [];
        foreach (array_keys($this->graph['nodes']) as $n) $adj[$n] = [];
        foreach ($this->graph['edges'] as $e) {
            $adj[$e['source']][] = $e['target'];
            $adj[$e['target']][] = $e['source'];
        }

        $partition = []; $label = 0;
        $visited   = [];
        foreach (array_keys($this->graph['nodes']) as $start) {
            if (isset($visited[$start])) continue;
            $queue = [$start];
            $visited[$start] = true;
            while (!empty($queue)) {
                $node = array_shift($queue);
                $partition[$node] = $label;
                foreach ($adj[$node] as $nb)
                    if (!isset($visited[$nb])) { $visited[$nb] = true; $queue[] = $nb; }
            }
            $label++;
        }
        return $partition;
    }

    private function calculateModularity(array $partition): float {
        $totalW = array_sum(array_column($this->graph['edges'], 'weight'));
        if ($totalW <= 0) return 0.0;

        $strength = array_fill_keys(array_keys($this->graph['nodes']), 0.0);
        foreach ($this->graph['edges'] as $e) {
            $strength[$e['source']] += $e['weight'];
            $strength[$e['target']] += $e['weight'];
        }

        $comms = [];
        foreach ($partition as $node => $c) $comms[$c][] = $node;

        $Q = 0.0;
        foreach ($comms as $members) {
            $mset   = array_flip($members);
            $within = 0.0; $sSum = 0.0;
            foreach ($members as $node) {
                $sSum += $strength[$node];
                foreach ($this->graph['edges'] as $e)
                    if (($e['source'] === $node && isset($mset[$e['target']])) ||
                        ($e['target'] === $node && isset($mset[$e['source']])))
                        $within += $e['weight'];
            }
            $Q += ($within / (2*$totalW)) - ($sSum / (2*$totalW))**2;
        }
        return $Q;
    }

    private function partitionStability(array $partitions): float {
        if (count($partitions) < 2) return 1.0;
        $sum = 0.0; $pairs = 0;
        for ($i = 0; $i < count($partitions); $i++)
            for ($j = $i+1; $j < count($partitions); $j++) {
                $sum += $this->adjustedRandIndex($partitions[$i], $partitions[$j]);
                $pairs++;
            }
        return $pairs > 0 ? $sum/$pairs : 1.0;
    }

    private function adjustedRandIndex(array $p1, array $p2): float {
        $nodes = array_keys($p1); $n = count($nodes);
        $cont  = [];
        foreach ($nodes as $nd) $cont[$p1[$nd]][$p2[$nd]] = ($cont[$p1[$nd]][$p2[$nd]] ?? 0) + 1;

        $sumC = $sumR = $sumCo = 0;
        $rowS = []; $colS = [];
        foreach ($cont as $c1 => $row) {
            $rowS[$c1] = array_sum($row);
            $sumR += $rowS[$c1] * ($rowS[$c1]-1) / 2;
            foreach ($row as $c2 => $cnt) {
                $sumC += $cnt*($cnt-1)/2;
                $colS[$c2] = ($colS[$c2] ?? 0) + $cnt;
            }
        }
        foreach ($colS as $s) $sumCo += $s*($s-1)/2;
        $total    = $n*($n-1)/2;
        $expected = $sumR*$sumCo / $total;
        $maxIdx   = ($sumR+$sumCo)/2;
        return ($maxIdx === $expected) ? 1.0 : ($sumC-$expected)/($maxIdx-$expected);
    }

    // ------------------------------------------------------------------------
    // 5. CENTRALITY - Exact Brandes Betweenness
    // ------------------------------------------------------------------------
    public function calculateCentrality(): array {
        $this->progress('centrality', 10);
        $this->brandesBetweenness();
        $this->progress('centrality', 100);
        return $this->graph['nodes'];
    }

    private function brandesBetweenness(): void {
        $nodes = array_keys($this->graph['nodes']);
        $bet   = array_fill_keys($nodes, 0.0);

        $adj = array_fill_keys($nodes, []);
        foreach ($this->graph['edges'] as $e) {
            $adj[$e['source']][] = $e['target'];
            $adj[$e['target']][] = $e['source'];
        }

        foreach ($nodes as $s) {
            $stack = []; $pred  = []; $sigma = array_fill_keys($nodes, 0);
            $dist  = array_fill_keys($nodes, -1);
            $sigma[$s] = 1; $dist[$s] = 0;
            $queue = [$s];

            while (!empty($queue)) {
                $v = array_shift($queue);
                array_unshift($stack, $v);
                foreach ($adj[$v] as $w) {
                    if ($dist[$w] < 0) { $queue[] = $w; $dist[$w] = $dist[$v]+1; }
                    if ($dist[$w] === $dist[$v]+1) {
                        $sigma[$w] += $sigma[$v];
                        $pred[$w][] = $v;
                    }
                }
            }

            $delta = array_fill_keys($nodes, 0.0);
            while (!empty($stack)) {
                $w = array_shift($stack);
                if (isset($pred[$w]))
                    foreach ($pred[$w] as $v)
                        $delta[$v] += ($sigma[$v]/$sigma[$w]) * (1.0 + $delta[$w]);
                if ($w !== $s) $bet[$w] += $delta[$w];
            }
        }

        $maxB   = max($bet) ?: 1.0;
        $maxDeg = max(array_column($this->graph['nodes'], 'degree')) ?: 1;

        foreach ($this->graph['nodes'] as $nid => &$node) {
            $node['betweenness'] = $bet[$nid] / $maxB;
            $nd = $node['degree'] / $maxDeg;
            $node['hub_score']   = $nd * 0.6 + $node['betweenness'] * 0.4;
        }
        unset($node);
    }

    // ------------------------------------------------------------------------
    // 6. FULL REPORT
    // ------------------------------------------------------------------------
    public function generateReport(): array {
        $this->detectInteractions();
        $this->buildGraph();
        $this->detectCommunities();
        $this->calculateCentrality();

        return [
            'residues'                 => $this->residues,
            'interactions'             => $this->interactions,
            'graph'                    => $this->graph,
            'communities'              => $this->communities,
            'stats'                    => $this->stats,
            'modularity'               => $this->modularity,
            'lpa_stability'            => $this->lpaStability,
            'singleton_count'          => $this->singletonCount,
            'thresholds'               => $this->thresholds,
            'using_default_thresholds' => $this->usingDefaultThresholds,
            'summary'                  => $this->buildSummary(),
        ];
    }

    private function buildSummary(): array {
        $totalDeg = array_sum(array_column($this->graph['nodes'], 'degree'));
        $nNodes   = count($this->graph['nodes']);

        $typeCounts = [];
        foreach ($this->interactions as $ix)
            $typeCounts[$ix['type']] = ($typeCounts[$ix['type']] ?? 0) + 1;
        arsort($typeCounts);

        $nodes = array_values($this->graph['nodes']);
        usort($nodes, fn($a,$b) => $b['hub_score'] <=> $a['hub_score']);
        $hubCount = min(15, max(5, (int)ceil($nNodes * 0.1)));

        return [
            'total_residues'     => $nNodes,
            'total_interactions' => count($this->interactions),
            'communities_found'  => count($this->communities),
            'singletons_removed' => $this->singletonCount,
            'average_degree'     => $nNodes > 0 ? $totalDeg/$nNodes : 0.0,
            'modularity'         => $this->modularity,
            'lpa_stability'      => $this->lpaStability,
            'hub_residues'       => array_slice($nodes, 0, $hubCount),
            'interaction_types'  => $typeCounts,
        ];
    }

    // -- Getters --------------------------------------------------------------
    public function getModularity(): float  { return $this->modularity; }
    public function getLPAStability(): float { return $this->lpaStability; }

    // -- Utility --------------------------------------------------------------
    private function dist(array $a, array $b): float {
        $dx=$a['x']-$b['x']; $dy=$a['y']-$b['y']; $dz=$a['z']-$b['z'];
        return sqrt($dx*$dx + $dy*$dy + $dz*$dz);
    }
}

// ----------------------------------------------------------------------------
// Visualizer
// ----------------------------------------------------------------------------
class ResCommVisualizer {

    private ResComm $rc;
    public function __construct(ResComm $rc) { $this->rc = $rc; }

    public function generateNetworkGraph(string $format = 'svg'): string {
        $data = $this->rc->generateReport();
        if ($format === 'dot') return $this->buildDot($data);
        return count($data['residues']) > 200
            ? $this->buildSimplifiedSVG($data)
            : $this->buildSVG($data);
    }

    // -- HTML Report ----------------------------------------------------------
    public function generateHTMLReport(array $data): string {
        $s   = $data['summary'];
        $st  = $data['stats'] ?? [];
        $th  = $data['thresholds'] ?? defaultThresholds();
        $isDefault = $data['using_default_thresholds'] ?? true;
        $sid = $_SESSION['current_analysis'] ?? '';

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>ResComm – Protein Network Analysis</title>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;margin:0;padding:0;background:#f8f9fa;color:#333;line-height:1.6}
.header{background:linear-gradient(135deg,#2c3e50,#1a2530);color:#fff;padding:30px 0;text-align:center;border-bottom:4px solid #3498db}
.header h1{margin:0;font-size:2.2em;font-weight:300;letter-spacing:1px}
.header .sub{margin-top:8px;color:#bdc3c7;font-size:1.05em}
.wrap{max-width:1200px;margin:0 auto;padding:20px}
.card{background:#fff;border-radius:8px;padding:25px;margin-bottom:25px;box-shadow:0 2px 4px rgba(0,0,0,.05);border:1px solid #e9ecef}
.card-title{color:#2c3e50;font-size:1.35em;margin:0 0 18px;padding-bottom:10px;border-bottom:2px solid #f0f0f0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:16px;margin:16px 0}
.stat{background:#f8f9fa;padding:18px;border-radius:6px;text-align:center;border-left:4px solid #3498db}
.stat-n{font-size:2.2em;font-weight:300;color:#2c3e50;margin:8px 0}
.stat-l{color:#7f8c8d;font-size:.8em;text-transform:uppercase;letter-spacing:1px}
.hub{display:inline-block;background:#fff3cd;color:#856404;padding:5px 11px;margin:3px;border-radius:4px;font-size:.88em;font-weight:500;border:1px solid #ffeaa7}
.itag{display:inline-block;padding:4px 12px;margin:3px 6px 3px 0;border-radius:20px;font-size:.83em;font-weight:500;color:#fff}
.cbox{margin:12px 0;padding:14px;background:#f8f9fa;border-radius:6px;border-left:4px solid}
.rtag{display:inline-block;padding:2px 7px;margin:2px;background:#fff;border-radius:3px;font-size:.83em;border:1px solid #e9ecef;font-family:"Courier New",monospace}
table{width:100%;border-collapse:collapse;margin:16px 0;background:#fff;border-radius:6px;overflow:hidden}
th{background:#2c3e50;color:#fff;padding:11px 14px;text-align:left;font-weight:500;font-size:.92em}
td{padding:11px 14px;border-bottom:1px solid #e9ecef;font-size:.92em}
tr:hover td{background:#f8f9fa}
.btn{display:inline-block;padding:9px 18px;margin:4px;background:#3498db;color:#fff;text-decoration:none;border-radius:4px;font-weight:500;border:none;cursor:pointer;font-size:.92em}
.btn:hover{background:#2980b9}
.btn-sec{background:#6c757d}.btn-sec:hover{background:#5a6268}
.btn-ok{background:#28a745}.btn-ok:hover{background:#218838}
.netbox{width:100%;overflow:auto;background:#fff;padding:16px;border-radius:6px;border:1px solid #e9ecef;margin:16px 0}
.infobox{background:#e8f4f8;border-left:4px solid #3498db;padding:14px;margin:16px 0;border-radius:4px;font-size:.93em}
.warnbox{background:#fff3cd;border-left:4px solid #ffc107;padding:14px;margin:16px 0;border-radius:4px;font-size:.93em}
.footer{text-align:center;margin-top:36px;padding:18px;color:#6c757d;font-size:.88em;border-top:1px solid #e9ecef}
</style>
</head>
<body>
<div class="header">
  <h1>ResComm Protein Network Analyzer</h1>
  <div class="sub">Residue interaction network analysis</div>
</div>
<div class="wrap">

<!-- -- Summary -- -->
<div class="card">
  <div class="card-title">Analysis Summary</div>
  <?php if (!empty($st)): ?>
  <div class="infobox">
    Lines parsed: <?= number_format($st['total_lines']) ?> |
    Water filtered: <?= number_format($st['water_molecules']) ?> |
    Non-protein filtered: <?= number_format($st['filtered_entities']) ?> |
    Protein residues: <?= number_format($st['protein_residues']) ?>
  </div>
  <?php endif; ?>
  <div class="infobox">
    <strong>Thresholds used<?= $isDefault ? ' (literature defaults)' : ' (user-modified — not the manuscript defaults)' ?>:</strong>
    H-bond &le; <?= number_format($th['hydrogen_bond'],2) ?> &Aring; ·
    Salt bridge &le; <?= number_format($th['salt_bridge'],2) ?> &Aring; ·
    Hydrophobic &le; <?= number_format($th['hydrophobic'],2) ?> &Aring; ·
    Disulfide (SG–SG) &le; <?= number_format($th['disulfide_sg'],2) ?> &Aring; ·
    Contact &le; <?= number_format($th['contact'],2) ?> &Aring;
  </div>
  <?php if (!$isDefault): ?>
  <div class="warnbox">
    These results were generated with non-default thresholds and are not directly comparable
    to the validation results reported in the ResComm manuscript, which used the literature defaults above.
  </div>
  <?php endif; ?>
  <?php if (($s['singletons_removed'] ?? 0) > 0): ?>
  <div class="warnbox">
    <?= $s['singletons_removed'] ?> singleton community/communities (size = 1) were removed.
    These are isolated residues with no community partners and carry no network-level biological meaning.
  </div>
  <?php endif; ?>
  <div class="grid">
    <div class="stat"><div class="stat-n"><?= number_format($s['total_residues']) ?></div><div class="stat-l">Residues</div></div>
    <div class="stat"><div class="stat-n"><?= number_format($s['total_interactions']) ?></div><div class="stat-l">Interactions</div></div>
    <div class="stat"><div class="stat-n"><?= $s['communities_found'] ?></div><div class="stat-l">Communities</div></div>
    <div class="stat"><div class="stat-n"><?= number_format($s['average_degree'],2) ?></div><div class="stat-l">Avg Degree</div></div>
    <div class="stat"><div class="stat-n"><?= number_format($s['modularity'],3) ?></div><div class="stat-l">Modularity Q</div></div>
    <div class="stat"><div class="stat-n"><?= number_format($s['lpa_stability'],3) ?></div><div class="stat-l">LPA Stability</div></div>
  </div>
</div>

<!-- -- Hub Residues -- -->
<?php if (!empty($s['hub_residues'])): ?>
<div class="card">
  <div class="card-title">Key Hub Residues (ranked by Hub Score)</div>
  <p style="color:#666;font-size:.9em;margin:0 0 12px">Hub Score = 0.6 × normalised degree + 0.4 × normalised betweenness centrality</p>
  <?php foreach ($s['hub_residues'] as $h): ?>
  <span class="hub"
    title="Degree: <?= $h['degree'] ?> | Betweenness: <?= number_format($h['betweenness'],3) ?> | Hub Score: <?= number_format($h['hub_score'],3) ?>">
    <?= htmlspecialchars($h['label']) ?> (deg:<?= $h['degree'] ?>)
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- -- Interaction Types -- -->
<?php if (!empty($s['interaction_types'])): ?>
<div class="card">
  <div class="card-title">Interaction Types</div>
  <?php foreach ($s['interaction_types'] as $type => $cnt): ?>
  <span class="itag" style="background:<?= $this->interactionColor($type) ?>">
    <?= ucfirst(str_replace('_',' ',$type)) ?>: <?= number_format($cnt) ?>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- -- Communities -- -->
<?php if (!empty($data['communities']) && count($data['communities']) <= 30): ?>
<div class="card">
  <div class="card-title">Detected Communities</div>
  <?php $colors=['#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c','#e67e22','#2c3e50'];
  foreach ($data['communities'] as $ci => $members):
    $col = $colors[$ci % count($colors)]; ?>
  <div class="cbox" style="border-color:<?= $col ?>">
    <strong>Community <?= $ci+1 ?> (<?= count($members) ?> residues)</strong><br>
    <?php foreach (array_slice($members, 0, 20) as $m): ?>
    <span class="rtag"><?= htmlspecialchars($data['graph']['nodes'][$m]['label']) ?></span>
    <?php endforeach; ?>
    <?php if (count($members) > 20): ?><span class="rtag">+<?= count($members)-20 ?> more</span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- -- Network Visualisation -- -->
<div class="card">
  <div class="card-title">Network Visualisation</div>
  <div class="netbox">
    <?php
    try {
        echo count($data['residues']) > 200 ? $this->buildSimplifiedSVG($data) : $this->buildSVG($data);
    } catch (\Exception $e) {
        echo '<p><em>Visualisation unavailable.</em></p>';
    }
    ?>
  </div>
</div>

<!-- -- Top Residues Table -- -->
<div class="card">
  <div class="card-title">Top 30 Residues by Hub Score</div>
  <table>
    <thead><tr><th>Residue</th><th>Degree</th><th>Betweenness</th><th>Hub Score</th><th>Chain</th><th>Community</th></tr></thead>
    <tbody>
    <?php
    $nodes = array_values($data['graph']['nodes']);
    usort($nodes, fn($a,$b) => $b['hub_score'] <=> $a['hub_score']);
    $nc = [];
    foreach ($data['communities'] as $ci => $members)
        foreach ($members as $m) $nc[$m] = $ci+1;
    foreach (array_slice($nodes, 0, 30) as $node): ?>
    <tr>
      <td><strong><?= htmlspecialchars($node['label']) ?></strong></td>
      <td><?= $node['degree'] ?></td>
      <td><?= number_format($node['betweenness'],3) ?></td>
      <td><?= number_format($node['hub_score'],3) ?></td>
      <td><?= htmlspecialchars($node['chain']) ?></td>
      <td><?= $nc[$node['id']] ?? '–' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- -- Downloads -- -->
<?php if ($sid): ?>
<div class="card">
  <div class="card-title">Download Results</div>
  <a href="?action=download&file=<?= urlencode($sid) ?>&type=json"  class="btn">JSON Data</a>
  <a href="?action=download&file=<?= urlencode($sid) ?>&type=svg"   class="btn">SVG Image</a>
  <a href="?action=download&file=<?= urlencode($sid) ?>&type=dot"   class="btn">DOT File</a>
  <a href="?action=download&file=<?= urlencode($sid) ?>&type=html"  class="btn btn-sec">HTML Report</a>
  <?php if (file_exists("output/{$sid}.png")): ?>
  <a href="?action=download&file=<?= urlencode($sid) ?>&type=png"   class="btn btn-ok">PNG Image</a>
  <?php endif; ?>
  <a href="?" class="btn btn-sec">New Analysis</a>
</div>
<?php endif; ?>

<div class="footer">
  <p>ResComm Protein Network Analyzer | 2026 | MIT License</p>
  <p>
    Modularity Q: range -0.5 to 1.0 (higher = stronger community structure).<br>
    LPA Stability &gt; 0.9 = highly reproducible communities across runs.<br>
    Thresholds used in this run: H-bond <?= number_format($th['hydrogen_bond'],2) ?> Å ·
    Salt bridge <?= number_format($th['salt_bridge'],2) ?> Å ·
    Hydrophobic <?= number_format($th['hydrophobic'],2) ?> Å ·
    Contact <?= number_format($th['contact'],2) ?> Å.
  </p>
</div>
</div>
</body>
</html>
        <?php return ob_get_clean();
    }

    // -- Color map -------------------------------------------------------------
    private function interactionColor(string $type): string {
        return ['disulfide'=>'#9B59B6','hydrogen_bond'=>'#3498DB',
                'salt_bridge'=>'#E74C3C','hydrophobic'=>'#F39C12',
                'contact'=>'#95A5A6'][$type] ?? '#666';
    }

    // -- DOT export ------------------------------------------------------------
    private function buildDot(array $data): string {
        $colors = ['#FF6B6B','#4ECDC4','#FFD166','#06D6A0','#118AB2','#FF9F1C','#2EC4B6','#E71D36'];
        $nc = [];
        foreach ($data['communities'] as $ci => $members) foreach ($members as $m) $nc[$m] = $ci;

        $dot  = "graph ProteinNetwork {\n    layout=fdp;\n    overlap=false;\n    splines=true;\n    K=1.5;\n";
        foreach ($data['graph']['nodes'] as $nid => $node) {
            $ci    = $nc[$nid] ?? 0;
            $color = $colors[$ci % count($colors)];
            $size  = 10 + ($node['degree']*1.5) + ($node['betweenness']*15);
            $fs    = 8  + ($node['hub_score']*8);
            $dot  .= sprintf(
                '    "%s" [label="%s",width=%.2f,height=%.2f,fillcolor="%s",style="filled,rounded",fontsize=%d];'."\n",
                $nid, $node['label'], $size/25, $size/25, $color, $fs);
        }
        foreach ($data['interactions'] as $ix) {
            $style='solid'; $color='#CCCCCC'; $pw=0.8;
            switch ($ix['type']) {
                case 'hydrogen_bond': $style='dashed'; $color='#3498DB'; $pw=1.2; break;
                case 'salt_bridge':   $style='bold';   $color='#E74C3C'; $pw=1.5; break;
                case 'hydrophobic':                    $color='#F39C12'; $pw=1.0; break;
                case 'disulfide':                      $color='#9B59B6'; $pw=2.0; break;
                case 'contact':                        $color='#95A5A6'; $pw=0.6; break;
            }
            $pw   = max(0.5, min(2.0, $pw * $ix['weight']));
            $dot .= sprintf('    "%s" -- "%s" [color="%s",style="%s",penwidth=%.2f];'."\n",
                $ix['res1'], $ix['res2'], $color, $style, $pw);
        }
        $dot .= "}\n";
        return $dot;
    }

    // -- Circular SVG (<= 200 residues) ----------------------------------------
    private function buildSVG(array $data): string {
        $W = 1000; $H = 800;
        $cx = $W/2; $cy = $H/2; $r = min($W,$H)*0.35;
        $total = max(1, count($data['residues']));

        $svg  = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= "<svg width=\"$W\" height=\"$H\" viewBox=\"0 0 $W $H\" xmlns=\"http://www.w3.org/2000/svg\">";
        $svg .= '<defs><style>'
            .'.node{cursor:pointer;transition:all .3s}'
            .'.node:hover circle{stroke:#000;stroke-width:3}'
            .'.edge{opacity:.55}'
            .'.label{font-family:Arial,sans-serif;font-size:9px;font-weight:bold;fill:#333}'
            .'</style></defs>';
        $svg .= "<rect width=\"100%\" height=\"100%\" fill=\"#f8f9fa\"/>";

        $residueKeys = array_keys($data['residues']);
        $keyIndex    = array_flip($residueKeys);

        foreach ($data['interactions'] as $ix) {
            if (!isset($data['residues'][$ix['res1']], $data['residues'][$ix['res2']])) continue;
            $i1 = $keyIndex[$ix['res1']]; $i2 = $keyIndex[$ix['res2']];
            $a1 = ($i1/$total)*2*M_PI;   $a2 = ($i2/$total)*2*M_PI;
            $x1 = $cx + $r*cos($a1);     $y1 = $cy + $r*sin($a1);
            $x2 = $cx + $r*cos($a2);     $y2 = $cy + $r*sin($a2);
            $sw = max(0.3, min(2.5, $ix['weight']*1.5));
            $stroke = $this->interactionColor($ix['type']);
            $svg .= "<line x1=\"$x1\" y1=\"$y1\" x2=\"$x2\" y2=\"$y2\" stroke=\"$stroke\" stroke-width=\"$sw\" class=\"edge\"/>";
        }

        $commColors = ['#FF6B6B','#4ECDC4','#FFD166','#06D6A0','#118AB2','#FF9F1C','#2EC4B6','#E71D36'];
        $nc = [];
        foreach ($data['communities'] as $ci => $members) foreach ($members as $m) $nc[$m] = $ci;

        foreach ($data['graph']['nodes'] as $nid => $node) {
            if (!isset($keyIndex[$nid])) continue;
            $idx   = $keyIndex[$nid];
            $angle = ($idx/$total)*2*M_PI;
            $nx    = $cx + $r*cos($angle);
            $ny    = $cy + $r*sin($angle);
            $nr    = 5 + ($node['hub_score']*12);
            $ci    = $nc[$nid] ?? 0;
            $color = $commColors[$ci % count($commColors)];
            $hub   = $node['hub_score'] > 0.7 ? ' filter="url(#glow)"' : '';

            $svg .= "<g class=\"node\">";
            $svg .= "<circle cx=\"$nx\" cy=\"$ny\" r=\"$nr\" fill=\"$color\" stroke=\"#333\" stroke-width=\"0.5\"$hub"
                   ." data-residue=\"".htmlspecialchars($nid)."\" data-community=\"".($ci+1)."\""
                   ." data-degree=\"{$node['degree']}\" data-betweenness=\"".number_format($node['betweenness'],3)."\"/>";
            if ($node['hub_score'] > 0.3 || $node['degree'] > 3)
                $svg .= "<text x=\"$nx\" y=\"".($ny-$nr-4)."\" text-anchor=\"middle\" class=\"label\">"
                       .htmlspecialchars($node['label'])."</text>";
            $svg .= "</g>";
        }
        $svg .= "</svg>";
        return $svg;
    }

    // -- Simplified linear SVG (> 200 residues) --------------------------------
    private function buildSimplifiedSVG(array $data): string {
        $W = 1200; $H = 800;
        $svg  = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= "<svg width=\"$W\" height=\"$H\" viewBox=\"0 0 $W $H\" xmlns=\"http://www.w3.org/2000/svg\">";
        $svg .= '<defs><style>'
            .'.node{cursor:pointer}'
            .'.edge{opacity:.25}'
            .'.label{font-size:8px;font-weight:bold;fill:#333}'
            .'</style></defs>';
        $svg .= "<rect width=\"100%\" height=\"100%\" fill=\"#f8f9fa\"/>";

        $maxSeq = max(array_column($data['residues'], 'resSeq')) ?: 1;

        usort($data['interactions'], fn($a,$b) => $b['weight'] <=> $a['weight']);
        $drawn = 0;
        foreach ($data['interactions'] as $ix) {
            if ($drawn >= 400) break;
            if ($ix['type'] === 'contact' && $ix['weight'] < 0.3) continue;
            if (!isset($data['residues'][$ix['res1']], $data['residues'][$ix['res2']])) continue;
            $r1 = $data['residues'][$ix['res1']]; $r2 = $data['residues'][$ix['res2']];
            $x1 = (($r1['resSeq']-1)/($maxSeq))*($W-200)+100;
            $x2 = (($r2['resSeq']-1)/($maxSeq))*($W-200)+100;
            $co1 = (ord($r1['chain'])-65)*30; $co2 = (ord($r2['chain'])-65)*30;
            $y1  = $H/2 + sin(($r1['resSeq']-1)*0.08)*120 + $co1;
            $y2  = $H/2 + sin(($r2['resSeq']-1)*0.08)*120 + $co2;
            $sw  = max(0.3, min(1.5, $ix['weight']));
            $svg .= "<line x1=\"$x1\" y1=\"$y1\" x2=\"$x2\" y2=\"$y2\""
                   ." stroke=\"".$this->interactionColor($ix['type'])."\" stroke-width=\"$sw\" class=\"edge\"/>";
            $drawn++;
        }

        static $cpk = [
            'ALA'=>'#C8C8C8','ARG'=>'#145AFF','ASN'=>'#00DCDC','ASP'=>'#E60A0A',
            'CYS'=>'#E6E600','GLN'=>'#00DCDC','GLU'=>'#E60A0A','GLY'=>'#EBEBEB',
            'HIS'=>'#8282D2','ILE'=>'#0F820F','LEU'=>'#0F820F','LYS'=>'#145AFF',
            'MET'=>'#E6E600','PHE'=>'#3232AA','PRO'=>'#DC9682','SER'=>'#FA9600',
            'THR'=>'#FA9600','TRP'=>'#B45AB4','TYR'=>'#3232AA','VAL'=>'#0F820F',
        ];

        foreach ($data['graph']['nodes'] as $nid => $node) {
            if ($node['hub_score'] < 0.4 && $node['degree'] < 6) continue;
            if (!isset($data['residues'][$nid])) continue;
            $res = $data['residues'][$nid];
            $x   = (($res['resSeq']-1)/($maxSeq))*($W-200)+100;
            $co  = (ord($res['chain'])-65)*30;
            $y   = $H/2 + sin(($res['resSeq']-1)*0.08)*120 + $co;
            $nr  = 3 + ($node['hub_score']*8);
            $col = $cpk[$res['resName']] ?? '#666';
            $svg .= "<g class=\"node\"><circle cx=\"$x\" cy=\"$y\" r=\"$nr\" fill=\"$col\" stroke=\"#333\" stroke-width=\"0.3\"/>";
            if ($node['hub_score'] > 0.5 || $node['degree'] > 6)
                $svg .= "<text x=\"$x\" y=\"".($y-$nr-3)."\" text-anchor=\"middle\" class=\"label\">"
                       .htmlspecialchars($node['label'])."</text>";
            $svg .= "</g>";
        }
        $svg .= "</svg>";
        return $svg;
    }
}

// ----------------------------------------------------------------------------
// Upload Form
// ----------------------------------------------------------------------------
function showUploadForm(): void {
    $d = defaultThresholds();
    $b = thresholdBounds();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>ResComm – Protein Network Analyzer</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;margin:0;padding:0;background:#f5f7fa;color:#333}
.wrap{max-width:800px;margin:0 auto;padding:40px 20px}
.hdr{text-align:center;margin-bottom:40px}
.hdr h1{color:#2c3e50;font-size:2.4em;font-weight:300;margin-bottom:10px}
.hdr p{color:#7f8c8d;font-size:1.05em;line-height:1.6}
.upload{background:#fff;border-radius:8px;padding:40px;box-shadow:0 4px 12px rgba(0,0,0,.08);margin-bottom:28px;border:2px dashed #e0e0e0;transition:border-color .3s}
.upload:hover{border-color:#3498db}
.file-inp{width:100%;padding:14px;margin-bottom:18px;border:1px solid #ddd;border-radius:4px;font-size:15px;box-sizing:border-box}
.btn{display:block;width:100%;padding:15px;background:#3498db;color:#fff;border:none;border-radius:4px;font-size:17px;font-weight:500;cursor:pointer;transition:background .3s}
.btn:hover{background:#2980b9}
.infobox{background:#e8f4f8;border-left:4px solid #3498db;padding:18px;margin:26px 0;border-radius:4px;font-size:.94em}
.features{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:18px;margin:36px 0}
.feat{background:#fff;padding:18px;border-radius:6px;text-align:center;box-shadow:0 2px 4px rgba(0,0,0,.05)}
.feat h3{margin:12px 0 8px;color:#2c3e50;font-size:1em}
.feat p{color:#7f8c8d;font-size:.88em;margin:0}
.samples{background:#fff;padding:24px;border-radius:8px;margin-top:36px}
.slinks{display:flex;flex-wrap:wrap;gap:9px;margin-top:14px}
.slink{display:inline-block;padding:7px 14px;background:#f8f9fa;color:#2c3e50;text-decoration:none;border-radius:4px;font-size:.88em;border:1px solid #e9ecef;transition:all .2s}
.slink:hover{background:#3498db;color:#fff;border-color:#3498db}
details.adv{background:#fff;border-radius:8px;padding:20px 24px;margin-bottom:28px;border:1px solid #e9ecef}
details.adv summary{cursor:pointer;font-weight:600;color:#2c3e50;font-size:1.02em}
.thgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:18px}
.thfield label{display:block;font-size:.85em;color:#555;margin-bottom:4px}
.thfield input{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:.92em}
.thfield .cite{color:#999;font-size:.78em;margin-top:3px}
.reset-link{display:inline-block;margin-top:14px;font-size:.85em;color:#3498db;text-decoration:none;cursor:pointer}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>ResComm</h1>
    <p>Automated residue interaction network analysis from PDB files</p>
  </div>
  <div class="upload">
    <form method="POST" action="?action=analyze" enctype="multipart/form-data" id="upForm">
      <input type="file" name="pdb_file" accept=".pdb,.ent,.txt" class="file-inp" required>

      <details class="adv" id="advPanel">
        <summary>Advanced: interaction distance thresholds (optional)</summary>
        <p style="color:#666;font-size:.88em;margin:10px 0 0">
          ResComm uses Ca–Ca distance cutoffs as proxies for interaction detection (see Methods /
          Limitations in the manuscript for the rationale and known trade-offs). The defaults below
          are literature-calibrated and are what the manuscript's validation results use. You may
          override them here to test sensitivity on your own structure; results computed with
          non-default values are flagged as such in the report and are not directly comparable to
          the published validation.
        </p>
        <div class="thgrid">
          <div class="thfield">
            <label for="th_hb">Hydrogen bond (Å)</label>
            <input type="number" step="0.01" id="th_hb" name="hydrogen_bond"
                   value="<?= $d['hydrogen_bond'] ?>" min="<?= $b['hydrogen_bond'][0] ?>" max="<?= $b['hydrogen_bond'][1] ?>">
            <div class="cite">Default 3.5 Å (McDonald &amp; Thornton 1994); allowed range <?= $b['hydrogen_bond'][0] ?>–<?= $b['hydrogen_bond'][1] ?> Å</div>
          </div>
          <div class="thfield">
            <label for="th_sb">Salt bridge (Å)</label>
            <input type="number" step="0.01" id="th_sb" name="salt_bridge"
                   value="<?= $d['salt_bridge'] ?>" min="<?= $b['salt_bridge'][0] ?>" max="<?= $b['salt_bridge'][1] ?>">
            <div class="cite">Default 4.5 Å (Kumar &amp; Nussinov 1999); allowed range <?= $b['salt_bridge'][0] ?>–<?= $b['salt_bridge'][1] ?> Å</div>
          </div>
          <div class="thfield">
            <label for="th_hp">Hydrophobic contact (Å)</label>
            <input type="number" step="0.01" id="th_hp" name="hydrophobic"
                   value="<?= $d['hydrophobic'] ?>" min="<?= $b['hydrophobic'][0] ?>" max="<?= $b['hydrophobic'][1] ?>">
            <div class="cite">Default 6.0 Å (Tsai et al. 1999); allowed range <?= $b['hydrophobic'][0] ?>–<?= $b['hydrophobic'][1] ?> Å</div>
          </div>
          <div class="thfield">
            <label for="th_ds">Disulfide, SG–SG (Å)</label>
            <input type="number" step="0.01" id="th_ds" name="disulfide_sg"
                   value="<?= $d['disulfide_sg'] ?>" min="<?= $b['disulfide_sg'][0] ?>" max="<?= $b['disulfide_sg'][1] ?>">
            <div class="cite">Default 2.56 Å (Thornton 1981); allowed range <?= $b['disulfide_sg'][0] ?>–<?= $b['disulfide_sg'][1] ?> Å</div>
          </div>
          <div class="thfield">
            <label for="th_ct">General contact (Å)</label>
            <input type="number" step="0.01" id="th_ct" name="contact"
                   value="<?= $d['contact'] ?>" min="<?= $b['contact'][0] ?>" max="<?= $b['contact'][1] ?>">
            <div class="cite">Default 8.0 Å (Brinda &amp; Vishveshwara 2005); allowed range <?= $b['contact'][0] ?>–<?= $b['contact'][1] ?> Å</div>
          </div>
        </div>
        <a class="reset-link" onclick="document.querySelectorAll('.thfield input').forEach(function(el){el.value=el.defaultValue;});return false;">Reset to defaults</a>
      </details>

      <button type="submit" class="btn" id="goBtn">Analyze Protein Structure</button>
    </form>
  </div>
  <div class="infobox">
    <strong>Default parameters:</strong>
    H-bond = 3.5 &Aring; Salt bridge = 4.5 &Aring;  Hydrophobic = 6.0 &Aring; Contact = 8.0 &Aring; (all user-adjustable above) |
    Weighted LPA community detection (50 iter, 10 runs, best Q) |
    Exact Brandes betweenness centrality |
    Grid optimization for &gt; 300 residues
  </div>
  <div class="features">
    <div class="feat"><h3>Smart Filtering</h3><p>Water &amp; non-protein entities removed automatically (11 water types)</p></div>
    <div class="feat"><h3>5 Interaction Types</h3><p>H-bonds, salt bridges, hydrophobic, disulfides, contacts — thresholds adjustable</p></div>
    <div class="feat"><h3>Communities</h3><p>Weighted LPA   10 runs  best modularity Q</p></div>
    <div class="feat"><h3>Exact Centrality</h3><p>Full Brandes betweenness + composite Hub Score</p></div>
  </div>
  <div class="samples">
    <h3>Sample PDB Files</h3>
    <p>Download any and upload above:</p>
    <div class="slinks">
      <a href="https://files.rcsb.org/download/1CRN.pdb" class="slink" target="_blank">1CRN  Crambin (46 aa)</a>
      <a href="https://files.rcsb.org/download/1UBQ.pdb" class="slink" target="_blank">1UBQ  Ubiquitin (76 aa)</a>
      <a href="https://files.rcsb.org/download/1LYZ.pdb" class="slink" target="_blank">1LYZ  Lysozyme (129 aa)</a>
      <a href="https://files.rcsb.org/download/1HIV.pdb" class="slink" target="_blank">1HIV  Protease (198 aa)</a>
      <a href="https://files.rcsb.org/download/1AQK.pdb" class="slink" target="_blank">1AQK  ~220 aa</a>
    </div>
  </div>
</div>
<script>
document.getElementById('upForm').addEventListener('submit', function () {
    var btn = document.getElementById('goBtn');
    btn.textContent = 'Analyzing… please wait';
    btn.disabled = true;
});
</script>
</body>
</html>
<?php }

// ----------------------------------------------------------------------------
// Analysis Handlers
// ----------------------------------------------------------------------------
function handleAnalysis(): void {
    session_start();
    if (!isset($_FILES['pdb_file']) || $_FILES['pdb_file']['error'] !== UPLOAD_ERR_OK)
        die("File upload error. Please try again.");

    $aid  = uniqid('rescomm_');
    $pid  = uniqid('progress_');
    $path = "uploads/{$aid}.pdb";

    if (!move_uploaded_file($_FILES['pdb_file']['tmp_name'], $path))
        die("Failed to save uploaded file.");

    $_SESSION['original_filename'] = $_FILES['pdb_file']['name'];

    // Validate and persist any user-supplied threshold overrides for this analysis.
    $_SESSION["thresholds_{$aid}"] = readThresholdOverrides($_POST);

    file_put_contents("temp/progress_{$pid}.txt", "starting:0");
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ResComm – Processing</title>
<style>
body{font-family:-apple-system,sans-serif;margin:0;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f5f7fa}
.box{background:#fff;border-radius:8px;padding:40px;width:90%;max-width:480px;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,.08)}
h2{color:#2c3e50;margin-bottom:6px}
.fn{color:#7f8c8d;margin-bottom:28px;word-break:break-all;font-size:.92em}
.bar{height:6px;background:#e0e0e0;border-radius:3px;margin:24px 0;overflow:hidden}
.fill{height:100%;background:#3498db;width:0%;transition:width .5s;border-radius:3px}
.status{color:#666;margin-top:16px;min-height:22px;font-size:.92em}
.loader{border:4px solid #f3f3f3;border-top:4px solid #3498db;border-radius:50%;width:38px;height:38px;animation:spin 1s linear infinite;margin:18px auto}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
<script>
var pid='<?= $pid ?>',aid='<?= $aid ?>';
var stages={starting:'Starting',parsing:'Parsing PDB',interactions:'Detecting interactions',
    graph:'Building graph',communities:'Community detection (LPA)',
    centrality:'Calculating betweenness centrality',done:'Complete'};

function poll(){
    fetch('?action=progress&pid='+pid+'&_='+Date.now())
    .then(r=>r.json()).then(d=>{
        var p=d.progress||'';if(!p||p==='0'){setTimeout(poll,1000);return;}
        var parts=p.split(':'),stage=parts[0],pct=parseInt(parts[1])||0;
        document.getElementById('fill').style.width=pct+'%';
        document.getElementById('status').textContent=(stages[stage]||stage)+' ('+pct+'%)';
        if(pct>=100){
            document.getElementById('status').textContent='Complete! Redirecting…';
            setTimeout(()=>{window.location.href='?action=view&id='+aid;},900);
        } else {
            setTimeout(poll,500);
        }
    }).catch(()=>setTimeout(poll,1000));
}
window.addEventListener('load',function(){
    fetch('?action=analyze_background&id='+aid+'&pid='+pid).catch(()=>{});
    setTimeout(poll,1500);
});
</script>
</head>
<body>
<div class="box">
  <h2>Analyzing Protein Structure</h2>
  <div class="fn"><?= htmlspecialchars($_FILES['pdb_file']['name']) ?></div>
  <div class="loader"></div>
  <div class="bar"><div class="fill" id="fill"></div></div>
  <div class="status" id="status">Initializing…</div>
</div>
</body>
</html>
<?php exit;
}

// ----------------------------------------------------------------------------
function handleBackgroundAnalysis(): void {
    session_start();
    $aid  = $_GET['id']  ?? '';
    $pid  = $_GET['pid'] ?? '';
    $path = "uploads/{$aid}.pdb";

    if (!file_exists($path)) { error_log("ResComm: file not found – $path"); exit; }

    ignore_user_abort(true);
    set_time_limit(300);

    $thresholds = $_SESSION["thresholds_{$aid}"] ?? defaultThresholds();

    try {
        $rc   = new ResComm($path, $pid, $thresholds);
        $report = $rc->generateReport();
        $viz  = new ResCommVisualizer($rc);

        $dot  = $viz->generateNetworkGraph('dot');
        $svg  = $viz->generateNetworkGraph('svg');
        $html = $viz->generateHTMLReport($report);

        $_SESSION[$aid] = [
            'report'   => $report,
            'dot'      => $dot,
            'svg'      => $svg,
            'html'     => $html,
            'filename' => $_SESSION['original_filename'] ?? 'protein.pdb',
        ];
        $_SESSION['current_analysis'] = $aid;

        file_put_contents("output/{$aid}.dot",  $dot);
        file_put_contents("output/{$aid}.svg",  $svg);
        file_put_contents("output/{$aid}.html", $html);
        file_put_contents("output/{$aid}.json", json_encode($report, JSON_PRETTY_PRINT));

        if (svgToPng("output/{$aid}.svg", "output/{$aid}.png", 1200))
            error_log("ResComm: PNG saved for $aid");
        else
            error_log("ResComm: PNG export failed for $aid (GD may lack required support)");

        file_put_contents("temp/progress_{$pid}.txt", "done:100");

    } catch (\Throwable $e) {
        error_log("ResComm error: ".$e->getMessage());
        file_put_contents("temp/progress_{$pid}.txt", "error:0");
        $_SESSION[$aid] = [
            'error'    => $e->getMessage(),
            'filename' => $_SESSION['original_filename'] ?? 'protein.pdb',
        ];
    }
    exit;
}

// ----------------------------------------------------------------------------
function viewAnalysis(string $aid): void {
    session_start();
    if (!isset($_SESSION[$aid])) {
        echo '<div style="padding:40px;text-align:center;font-family:sans-serif">'
            .'Analysis not found or session expired. <a href="/">Please re-upload your file.</a></div>';
        return;
    }
    if (isset($_SESSION[$aid]['error'])) {
        echo '<div style="padding:40px;text-align:center;font-family:sans-serif;color:#c0392b">'
            .'Error: '.htmlspecialchars($_SESSION[$aid]['error']).'</div>';
        return;
    }
    echo $_SESSION[$aid]['html'];
}

// ----------------------------------------------------------------------------
function downloadFile(): void {
    session_start();
    $aid  = $_GET['file'] ?? '';
    $type = $_GET['type'] ?? 'json';
    if (!isset($_SESSION[$aid])) { http_response_code(404); die("File not found."); }

    $base = pathinfo($_SESSION[$aid]['filename'], PATHINFO_FILENAME);

    switch ($type) {
        case 'dot':
            header('Content-Type: text/plain');
            header("Content-Disposition: attachment; filename=\"{$base}.dot\"");
            echo $_SESSION[$aid]['dot'];
            break;
        case 'svg':
            header('Content-Type: image/svg+xml');
            header("Content-Disposition: attachment; filename=\"{$base}.svg\"");
            echo $_SESSION[$aid]['svg'];
            break;
        case 'png':
            $f = "output/{$aid}.png";
            if (file_exists($f)) {
                header('Content-Type: image/png');
                header("Content-Disposition: attachment; filename=\"{$base}.png\"");
                readfile($f);
            } else {
                http_response_code(404);
                die("PNG not available. Re-run the analysis on a server with GD support.");
            }
            break;
        case 'html':
            header('Content-Type: text/html; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$base}.html\"");
            echo $_SESSION[$aid]['html'];
            break;
        default:
            header('Content-Type: application/json');
            header("Content-Disposition: attachment; filename=\"{$base}.json\"");
            echo json_encode($_SESSION[$aid]['report'], JSON_PRETTY_PRINT);
    }
    exit;
}
