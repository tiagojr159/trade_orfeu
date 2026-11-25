# Segurança

O projeto aceita apenas `PAPER_TRADING`. Qualquer configuração de modo diferente é recusada. As APIs GET fornecem leitura. Os comandos privilegiados da CLI exigem acesso ao ambiente privado do usuário da aplicação.

No XAMPP desta entrega, `root` sem senha foi habilitado na configuração local conforme autorizado. Esse perfil recusa chamadas HTTP não locais. Com `APP_ENV=hostgator`, a aplicação exige schema com sufixo `_orfeu`, usuário dedicado com senha e bloqueia o opt-in de root. A guarda confirma o schema após conectar. Mantenha PHP corrigido e HTTPS na hospedagem.

Em Apache, use apenas `public/` como DocumentRoot ou publique seus arquivos separadamente. No XAMPP sem vhost, a regra de rewrite protege diretórios privados. O `.env`, os dados brutos e as migrations ficam fora da árvore pública. A API evita stack traces, credenciais e arquivos brutos. Confira o controle de acesso a todo o diretório público com o recurso de privacidade do cPanel se as informações ou os termos do feed exigirem acesso privado.

Para habilitar a administração HTTP por opção, defina localmente `ADMIN_PASSWORD_HASH` com um hash produzido por `password_hash`. A obtenção do token começa em `GET /api/v1/admin/session`. Use o cookie de sessão e `X-CSRF-Token` nas chamadas JSON POST. Envie `{"password":"..."}` em `admin/login`, use o novo token e envie `{"season_id":1}` em `admin/pause` ou `admin/resume`. `admin/season` aceita `name` e `capital`; `admin/promote` aceita hashes `model` e `evidence`. A sessão expira em 30 minutos. A autenticação limita tentativas por IP e janela no banco. É possível manter administração HTTP desligada e usar a CLI.

Artefatos são importados pelo SHA-256 informado e por esquema de inferência; ficam limitados a JSON. Mantenha os hashes junto do processo que gerou os arquivos. Eventos e notícias são exibidos como dados. Os textos da UI usam `textContent` e as exportações CSV neutralizam células que possam ser interpretadas como fórmula.
