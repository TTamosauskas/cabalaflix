# MSflix YouTube Cast — GitHub Pages

Plugin WordPress substituto para a ponte de Chromecast usada pelo CabalaFlix.

## O que ele faz

- Mantém os endpoints esperados pelo frontend:
  - `GET /wp-json/msflix/v1/youtube-cast-health`
  - `POST /wp-json/msflix/v1/youtube-cast`
- Aceita as ações `play` e `enqueue`.
- Autoriza explicitamente a origem `https://ttamosauskas.github.io`.
- Mantém a mesma origem do próprio WordPress autorizada.
- Rejeita outras origens com HTTP 403.
- Usa a YouTube Lounge API para obter `loungeToken`, fazer o bind e enviar `setPlaylist`/`addVideo`.
- Envia `Content-Length` explicitamente nas chamadas para o YouTube.

## Instalação

1. No WordPress, vá em **Plugins > Adicionar novo > Enviar plugin**.
2. Envie o ZIP deste pacote.
3. Ative **MSflix YouTube Cast — GitHub Pages**.
4. Depois de confirmar que o Cast funciona, desative o plugin antigo **MSflix YouTube Cast** para evitar duplicidade.

O plugin novo registra as mesmas rotas com prioridade alta e override, então pode ser ativado para teste antes de desativar o antigo.

## Teste rápido

Abra:

`https://mortesubita.net/wp-json/msflix/v1/youtube-cast-health`

A resposta deve conter `"ok": true` e versão `2.0.0`.

Depois abra o CabalaFlix no GitHub Pages, conecte ao Chromecast e selecione um vídeo.

## Segurança

A whitelist padrão é:

- `https://ttamosauskas.github.io`
- origem do `home_url()` do WordPress
- origem do `site_url()` do WordPress

Ela pode ser estendida por código usando o filtro WordPress `msflix_youtube_cast_allowed_origins`.

## Gerar o ZIP

Na raiz do repositório:

```bash
python3 scripts/build_wordpress_plugin.py
```

O arquivo será gerado em `dist/msflix-youtube-cast-github-pages.zip`.

## Observação

A YouTube Lounge API é uma interface não documentada e pode mudar. O plugin retorna mensagens de erro detalhadas quando o YouTube rejeita alguma etapa da sessão.
