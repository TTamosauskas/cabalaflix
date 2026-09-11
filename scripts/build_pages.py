from pathlib import Path

SOURCE = Path("index.html")
OUT_DIR = Path("_site")
OUT = OUT_DIR / "index.html"

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

OUT_DIR.mkdir(parents=True, exist_ok=True)
OUT.write_text(html, encoding="utf-8")
print(f"Built {OUT} ({OUT.stat().st_size} bytes) with Chromecast diagnostics")
