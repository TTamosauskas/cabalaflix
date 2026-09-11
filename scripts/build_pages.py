from pathlib import Path
import json
import urllib.request
import xml.etree.ElementTree as ET

SOURCE = Path("index.html")
OUT_DIR = Path("_site")
OUT = OUT_DIR / "index.html"
CHANNEL_ID = "UCLoIrIrw3ekjA2xE5yh0leA"
YOUTUBE_FEED = f"https://www.youtube.com/feeds/videos.xml?channel_id={CHANNEL_ID}"
LATEST_LIMIT = 15

html = SOURCE.read_text(encoding="utf-8")

replacements = [
    (
        'catch(e){if(e?.name==="AbortError")throw new Error("O WordPress demorou demais para responder ao YouTube Cast");throw e}finally{clearTimeout(timer)}',
        'catch(e){if(e?.name==="AbortError")throw new Error("O WordPress demorou demais para responder ao YouTube Cast");if(e instanceof TypeError)throw new Error(`Falha de rede/CORS ao acessar ${endpoint}: ${String(e?.message||e)}`);throw e}finally{clearTimeout(timer)}',
        "network/CORS diagnostic",
    ),
    (
        'if(session){openItem(x,{remote:true});const ok=await castItem(x);if(!ok&&els.player)els.player.innerHTML=remotePlayerMarkup(x,"Falha ao transmitir para o Chromecast");return}',
        'if(session){openItem(x,{remote:true});await castItem(x);return}',
        "preserve detailed Cast error",
    ),
]

for old, new, label in replacements:
    count = html.count(old)
    if count != 1:
        raise SystemExit(f"Expected exactly one match for {label}, found {count}")
    html = html.replace(old, new, 1)


def fetch_latest_videos():
    request = urllib.request.Request(
        YOUTUBE_FEED,
        headers={
            "User-Agent": "Mozilla/5.0 CabalaFlix-GitHub-Pages/1.0",
            "Accept": "application/atom+xml,application/xml,text/xml,*/*",
        },
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        payload = response.read()

    root = ET.fromstring(payload)
    ns = {
        "atom": "http://www.w3.org/2005/Atom",
        "yt": "http://www.youtube.com/xml/schemas/2015",
    }

    videos = []
    for entry in root.findall("atom:entry", ns):
        video_id = (entry.findtext("yt:videoId", default="", namespaces=ns) or "").strip()
        title = (entry.findtext("atom:title", default="", namespaces=ns) or "").strip()
        if video_id and title:
            videos.append([video_id, title])
        if len(videos) >= LATEST_LIMIT:
            break
    return videos


def merge_latest_into_catalog(page_html, latest):
    prefix = "const RAW_CATALOG="
    suffix = ";\nconst CATALOG=RAW_CATALOG.map"
    start = page_html.index(prefix) + len(prefix)
    end = page_html.index(suffix, start)

    catalog = json.loads(page_html[start:end])
    latest_ids = {item[0] for item in latest}
    merged = latest + [item for item in catalog if item[0] not in latest_ids]
    compact = json.dumps(merged, ensure_ascii=False, separators=(",", ":"))
    return page_html[:start] + compact + page_html[end:]


try:
    latest = fetch_latest_videos()
    if latest:
        html = merge_latest_into_catalog(html, latest)
        print(f"YouTube feed synced: {len(latest)} recent videos; newest = {latest[0][1]} ({latest[0][0]})")
    else:
        print("YouTube feed returned no videos; keeping embedded RAW_CATALOG")
except Exception as exc:
    print(f"YouTube feed sync failed ({exc}); keeping embedded RAW_CATALOG")

OUT_DIR.mkdir(parents=True, exist_ok=True)
OUT.write_text(html, encoding="utf-8")
print(f"Built {OUT} ({OUT.stat().st_size} bytes) with Chromecast diagnostics and refreshed Mais recentes")
