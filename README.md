# ResComm — Residue Interaction Network Analyzer

ResComm is a single-file PHP web application that performs complete residue
interaction network (RIN) analysis from a user-uploaded PDB file, with no
installation, no programming, and no external dependencies. Upload a
structure and ResComm returns interaction detection, community structure,
centrality, and hub-residue ranking in a single browser session.

Live demo: http://deepairesearch.org/rescomm/

If you use ResComm in your research, please cite:

> [Authors]. ResComm: Automated Residue Interaction Network Analysis with
> Integrated Community Detection for Protein Structures. [Journal, year].
> Source code archived at Zenodo: https://doi.org/10.5281/zenodo.14859494

## Features

- **Five interaction types**: hydrogen bonds, salt bridges, hydrophobic
  contacts, disulfide bonds, and general contacts, detected from Cα–Cα
  (and SG–SG for disulfides) distances.
- **User-adjustable distance thresholds.** All five cutoffs are exposed as
  optional fields on the upload form and are bounds-checked server-side.
  Defaults are the literature-calibrated values used in the accompanying
  manuscript (see [Interaction thresholds](#interaction-thresholds) below);
  runs using non-default values are clearly flagged in the report.
- **Automatic filtering** of water (11 recognised naming conventions) and
  common non-protein entities (crystallisation additives, cofactors, ions)
  before network construction.
- **Community detection** via a weighted Label Propagation Algorithm,
  10 independent runs with best-modularity selection, and mean Adjusted
  Rand Index reported as a stability measure. Falls back to connected-
  components (BFS) for sparse networks (<100 edges), where LPA is
  unreliable.
- **Exact Brandes betweenness centrality** for every residue, combined
  with normalised degree into a composite hub score.
- **Grid-accelerated interaction detection** (O(n·k) instead of O(n²)) for
  structures over 300 residues.
- **Five export formats**: interactive HTML report, JSON, SVG, PNG, and
  Graphviz DOT.
- **Zero external dependencies.** Pure PHP + the GD extension for PNG
  export; no database, no build step.

## Requirements

- PHP 7.4 or later, with the GD extension enabled
- A standard web server: Apache 2.4+, Nginx 1.18+, or IIS 10+
- Minimum 512 MB RAM (2 GB recommended for structures with more than
  ~300 residues)

Tested on PHP 7.4, 8.0, and 8.1. Verified in Google Chrome 120+,
Firefox 121+, Safari 17+, and Microsoft Edge 120+ on Windows 10/11,
macOS 13+, and Ubuntu 22.04.

## Installation

1. Copy `rescomm.php` to a directory served by your web server.
2. Ensure the web server process can create subdirectories next to the
   script — ResComm automatically creates `uploads/`, `output/`, and
   `temp/` on first run (used for uploaded structures, generated reports,
   and progress-polling files, respectively). No manual setup is required
   beyond write permissions on the parent directory.
3. Confirm the PHP GD extension is enabled (`php -m | grep gd`) if you
   want PNG export to work; all other features function without it.
4. Open the script's URL in a browser (e.g.
   `http://your-server/rescomm.php`) to reach the upload form.

No database, Composer dependencies, or build step is required.

## Usage

1. Upload a `.pdb`, `.ent`, or `.txt` structure file via the form.
2. (Optional) Expand **Advanced: interaction distance thresholds** to
   override any of the five default cutoffs for sensitivity testing.
3. ResComm parses the structure, detects interactions, builds the graph,
   runs community detection and centrality analysis, and redirects to an
   interactive HTML report — typically in well under 20 seconds for
   structures under a few hundred residues.
4. Download results as JSON, SVG, PNG, DOT, or the standalone HTML report
   from the report page.

### Sample structures

- [1CRN](https://files.rcsb.org/download/1CRN.pdb) — Crambin (46 residues)
- [1UBQ](https://files.rcsb.org/download/1UBQ.pdb) — Ubiquitin (76 residues)
- [1LYZ](https://files.rcsb.org/download/1LYZ.pdb) — Lysozyme (129 residues)
- [1HIV](https://files.rcsb.org/download/1HIV.pdb) — HIV-1 protease (198 residues)
- [1AQK](https://files.rcsb.org/download/1AQK.pdb) — ~220 residues

## Interaction thresholds

| Interaction type       | Default cutoff | Adjustable range | Reference                          |
|-------------------------|:-------------:|:-----------------:|-------------------------------------|
| Hydrogen bond            | 3.5 Å (Cα–Cα) | 2.5 – 5.0 Å        | McDonald & Thornton (1994)          |
| Salt bridge               | 4.5 Å (Cα–Cα) | 3.0 – 8.0 Å        | Kumar & Nussinov (1999)             |
| Hydrophobic contact        | 6.0 Å (Cα–Cα) | 3.0 – 10.0 Å       | Tsai et al. (1997)                  |
| Disulfide bond (SG–SG)      | 2.56 Å        | 1.8 – 3.5 Å        | Thornton (1981)                     |
| General contact              | 8.0 Å (Cα–Cα) | 5.0 – 12.0 Å       | Brinda & Vishveshwara (2005)        |

Disulfide bonds fall back to a fixed 6.5 Å Cα–Cα cutoff when SG atom
coordinates are unavailable in the structure (this fallback is not
user-adjustable).

ResComm uses Cα–Cα distances as a computationally efficient proxy for
interaction detection rather than all-atom geometry; see the accompanying
manuscript's Methods and Limitations sections for a full discussion of
this trade-off, including why thresholds are applied as hard cutoffs
rather than a continuous (harmonic) potential.

**Results generated with non-default thresholds are labelled as such** in
the HTML report and JSON output and are not directly comparable to the
validation results reported in the manuscript, which used the defaults
above throughout.

## Output

Each analysis produces:

- An interactive **HTML report** with summary statistics, hub residues,
  interaction-type breakdown, detected communities, an inline network
  visualisation, and a top-30 hub residue table.
- **JSON** containing the full parsed structure, interaction list, graph,
  communities, and summary statistics.
- **SVG** network visualisation (circular chord layout for ≤200 residues;
  simplified linear layout for larger structures).
- **PNG** raster export of the SVG (requires the PHP GD extension).
- **DOT** file for import into Graphviz or other graph-visualisation tools.

## How it works (brief)

1. **Parsing**: `ATOM`/`HETATM` records are read per PDB v3.3 column
   specifications; water (11 naming variants) and common non-protein
   entities are filtered; residue centres default to the Cα atom, falling
   back to a side-chain-atom centroid or all-atom centroid when Cα is
   absent.
2. **Interaction detection**: disulfide bonds are detected first via
   SG–SG distance; remaining pairs are classified by a hierarchical
   priority scheme (hydrogen bond → salt bridge → hydrophobic → general
   contact), each requiring both threshold and residue-type criteria.
   Structures over 300 residues use a 3D spatial grid keyed to the
   contact threshold for O(n·k) performance.
3. **Graph construction**: an undirected weighted graph is built, with
   edge weight = interaction-type base weight × distance decay
   (disulfide bonds receive a flat weight).
4. **Community detection**: weighted Label Propagation Algorithm, 10
   independent runs (up to 50 iterations each), best modularity (Q)
   selected; singleton communities are filtered and disclosed separately.
   Sparse networks (<100 edges) use connected-components instead, since
   LPA is unreliable at low edge density.
5. **Centrality**: exact Brandes betweenness centrality for every node;
   hub score = 0.6 × normalised degree + 0.4 × normalised betweenness.

## Limitations

- Cα-only interaction proxies do not capture side-chain orientation and
  can produce false positives/negatives relative to all-atom analysis.
- Legacy PDB format only; PDBx/mmCIF support (needed for structures
  exceeding the ~99,999-atom PDB format limit) is planned.
- Visualisation is static SVG/PNG; there is no interactive 3D viewer.
- The five-protein validation panel accompanying the manuscript is an
  illustrative set, not a curated benchmark with independently annotated
  ground truth.

See the manuscript's Discussion (Section 4.3) for full details and
planned enhancements.

## License

MIT License. See [LICENSE](LICENSE) for the full text.

## Related tools

ResComm is designed to complement, not replace, existing residue-
interaction and RIN tools:

- [RING 4.0](http://protein.bio.unipd.it/ring/) — exhaustive all-atom
  interaction detection (7 types), no built-in community/centrality
  analysis.
- [PIC](http://crick.mbu.iisc.ernet.in/~PIC) — web-based interaction
  calculator with user-adjustable thresholds and solvent-accessibility
  analysis; returns interaction lists, not a network.
- [ProtInter](https://github.com/maxibor/protinter) — Python CLI
  interaction calculator with per-threshold parameters; also returns
  interaction lists rather than a network.

ResComm's niche is integrating parsing, multi-type interaction detection,
community assignment, betweenness computation, and hub scoring into a
single installation-free, browser-based workflow.

## Contributing

Issues and pull requests are welcome. Please open an issue before
submitting a large change so it can be discussed first.

## Contact

[Corresponding author email — see manuscript]
