from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_NAME = "msflix-youtube-cast-github-pages"
PLUGIN_DIR = ROOT / "wordpress" / "plugins" / PLUGIN_NAME
DIST_DIR = ROOT / "dist"
ZIP_PATH = DIST_DIR / f"{PLUGIN_NAME}.zip"

if not PLUGIN_DIR.exists():
    raise SystemExit(f"Plugin directory not found: {PLUGIN_DIR}")

DIST_DIR.mkdir(parents=True, exist_ok=True)
if ZIP_PATH.exists():
    ZIP_PATH.unlink()

with zipfile.ZipFile(ZIP_PATH, "w", zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(PLUGIN_DIR.rglob("*")):
        if not path.is_file():
            continue
        archive.write(path, path.relative_to(PLUGIN_DIR.parent))

print(f"Built {ZIP_PATH} ({ZIP_PATH.stat().st_size} bytes)")
