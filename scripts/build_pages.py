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

latest_sync = r'''
const LATEST_VIDEOS_CACHE_KEY="cabalaflix.latest-videos.v2";
let latestVideosPromise=null;
function mergeLatestVideos(items){
  const incoming=(Array.isArray(items)?items:[]).map((v,index)=>({id:String(v?.id||"").trim(),title:String(v?.title||"").trim(),published:String(v?.published||""),index,channelTitle:CHANNEL.name})).filter(v=>v.id&&v.title);
  if(!incoming.length)return false;
  const freshIds=new Set(incoming.map(v=>v.id));
  const merged=[...incoming,...CATALOG.filter(v=>!freshIds.has(v.id))];
  CATALOG.splice(0,CATALOG.length,...merged);
  CATALOG.forEach((v,index)=>v.index=index);
  return true;
}
function hydrateLatestVideosCache(){
  try{
    const cached=JSON.parse(localStorage.getItem(LATEST_VIDEOS_CACHE_KEY)||"null");
    if(!cached||!Array.isArray(cached.items)||Date.now()-Number(cached.savedAt||0)>7*24*60*60*1000)return false;
    return mergeLatestVideos(cached.items);
  }catch{return false}
}
function refreshLatestVideos(){
  if(latestVideosPromise)return latestVideosPromise;
  latestVideosPromise=(async()=>{
    const root=window.CABALAFLIX_WP_REST_ROOT||"https://mortesubita.net/wp-json/";
    const endpoint=new URL("msflix/v1/latest-videos",root).href;
    const response=await fetch(endpoint,{cache:"no-store",credentials:"omit",headers:{Accept:"application/json"}});
    const data=await response.json().catch(()=>({}));
    if(!response.ok||data?.ok===false)throw new Error(String(data?.message||data?.error||`HTTP ${response.status}`));
    if(!mergeLatestVideos(data?.items))throw new Error("A atualização não retornou vídeos");
    try{localStorage.setItem(LATEST_VIDEOS_CACHE_KEY,JSON.stringify({savedAt:Date.now(),items:data.items}))}catch{}
    if(pageMode==="home")renderHomeContent();
    return true;
  })().catch(e=>{console.warn("Latest videos",e);return false}).finally(()=>{latestVideosPromise=null});
  return latestVideosPromise;
}
'''.strip()

init_old = '''function init(){
  els.topicBar.innerHTML=CATEGORY_DEFS.map(c=>`<button class="topic" data-category="${esc(c.name)}">${esc(c.name)}</button>`).join("");
  renderHome();
  requestAnimationFrame(fitSiteLink);
}'''
init_new = latest_sync + '''
function init(){
  els.topicBar.innerHTML=CATEGORY_DEFS.map(c=>`<button class="topic" data-category="${esc(c.name)}">${esc(c.name)}</button>`).join("");
  hydrateLatestVideosCache();
  renderHome();
  refreshLatestVideos();
  requestAnimationFrame(fitSiteLink);
}'''

count = html.count(init_old)
if count != 1:
    raise SystemExit(f"Expected exactly one init block for latest-video sync, found {count}")
html = html.replace(init_old, init_new, 1)

OUT_DIR.mkdir(parents=True, exist_ok=True)
OUT.write_text(html, encoding="utf-8")
print(f"Built {OUT} ({OUT.stat().st_size} bytes) with Chromecast diagnostics and automatic latest-video sync")
