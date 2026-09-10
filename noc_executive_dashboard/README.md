# NOC Executive Dashboard (módulo Zabbix 7.4)

Dashboard executivo para o **NOC** no estilo das telas do SOC: indicadores de
detecção/resposta (MTTD, MTTA, MTTR), mix de severidade, backlog e — o principal —
a separação entre **ações de automação** (WhatsApp, Email HTML NOC, Chamado Cervello)
e **ações humanas** (analistas do grupo `Monitor`), com visão **multi-tenant** por
grupo de hosts.

Fonte de dados: **API do Zabbix** (`event.get`, `alert.get`, `usergroup.get`,
`hostgroup.get`) — sem consultas diretas ao banco.

---

## Estrutura de arquivos

```
noc_executive_dashboard/
├── manifest.json                          # registro do módulo + menu "NOC"
├── Module.php                             # injeta o menu NOC → Dashboard Executivo
├── actions/
│   ├── ExecutiveOverview.php              # controller da página (shell)
│   └── ExecutiveData.php                  # endpoint AJAX (JSON) com toda a agregação
├── views/
│   ├── noc.executive.overview.php         # view (CHtmlPage) + assets
│   └── js/noc.executive.overview.js.php   # bootstrap JS inline (config + init)
├── partials/
│   └── noc.executive.body.php             # scaffolding: filtros, cards, donut, gauge, tabela
├── assets/
│   ├── css/noc-dashboard.css              # tema dark (estilo SOC)
│   └── js/noc-dashboard.js                # loader, formatação e desenho de donut/gauge
└── README.md
```

## Instalação

1. Copie a pasta `noc_executive_dashboard/` para o diretório de módulos do frontend:

   ```
   /usr/share/zabbix/modules/noc_executive_dashboard/
   ```

   (Em instalações via container/pacote o caminho pode variar; é o diretório
   `ui/modules` do frontend.)

2. No frontend, vá em **Administração → Geral → Módulos** e clique em
   **Verificar mudanças** (Scan directory).

3. O módulo **NOC Executive Dashboard** aparecerá na lista. Clique em **Desabilitado**
   para **Habilitar**.

4. Um novo item de menu **NOC → Dashboard Executivo** aparecerá no menu principal.

## Configuração (nomes de media type / grupo)

Como **cada cliente tem seu próprio grupo de hosts e seu próprio usuário de envio
de alertas**, a classificação **não** depende do nome do usuário. As regras são:

- **Automação** → alerta enviado por um dos *media types* de automação.
- **Humano** → ação de reconhecimento/mensagem feita por usuário do grupo `Monitor`.

Se os nomes mudarem no seu ambiente, ajuste as constantes no topo de
`actions/ExecutiveData.php`:

```php
private const AUTOMATION_MEDIATYPES = ['WhatsApp', 'Email HTML NOC Betta', 'Chamado Cervello'];
private const MEDIATYPE_WHATSAPP    = 'WhatsApp';
private const MEDIATYPE_EMAIL       = 'Email HTML NOC Betta';
private const MEDIATYPE_CERVELLO    = 'Chamado Cervello';
private const HUMAN_USER_GROUP      = 'Monitor';
private const TENANT_SEPARATOR      = '|';   // "BLUE6IX | LINUX" → tenant "BLUE6IX"
```

> Os mesmos valores default estão em `manifest.json` (bloco `config`) apenas como
> documentação; a fonte da verdade em tempo de execução são as constantes do
> controller.

## Como os indicadores são calculados

| Indicador | Origem |
|-----------|--------|
| **Open / New / Closed** | `event.get` (problemas no período); resolvido = tem `r_eventid` |
| **Unassigned** | evento aberto sem nenhum acknowledge |
| **MTTA / MTTR-respond** | `event.clock` → primeiro acknowledge |
| **MTTR-resolve** | `event.clock` → recovery (`r_eventid.clock`) |
| **MTTD** | 0 (o `event.clock` do Zabbix já é o instante de detecção) |
| **Severity mix** | severidade da trigger mapeada em Critical/High/Medium/Low/Info |
| **Automação (WhatsApp/Email/Cervello)** | `alert.get` filtrado pelos media types de automação |
| **Ações humanas** | acknowledges cujo `userid` pertence ao grupo `Monitor` |
| **Por tenant** | prefixo do grupo de hosts antes do separador `\|` |
| **Risk level** | score 0–100 ponderado por backlog aberto × severidade |

Os tempos são reportados como **média (mean)** com **p50 / p90**.

## Multi-tenant

O filtro **All Tenants / <cliente>** usa os grupos de hosts. O nome do cliente é o
texto antes do `|`:

- `BLUE6IX` → tenant **BLUE6IX**
- `BLUE6IX | LINUX` → tenant **BLUE6IX**
- `BLUE6IX | WINDOWS` → tenant **BLUE6IX**

## Filtros e atualização

- Períodos: **Today / 6H / 24H / 7D / 14D / 30D**.
- **Auto-refresh** a cada 60s (ajustável em `noc.executive.overview.js.php`, campo `refreshMs`).
- Botão **Refresh** para atualização manual.

## Observações / limitações

- **MTTD** aparece como `—` porque no Zabbix o timestamp do evento já é a detecção.
  Se você tiver um item/serviço que represente o instante real da falha, dá para
  calcular MTTD real — abra um follow-up para plugarmos essa fonte.
- **Reopened** hoje é `0`: o Zabbix não tem um estado nativo de "reaberto" para
  eventos de trigger. Se você usa um fluxo específico (ex.: tag/severidade), dá para
  derivar — me avise a regra.
- O segundo dashboard do SOC (**Analyst Performance / Leaderboard**) ainda não foi
  incluído nesta versão; é o próximo passo natural (usa os mesmos dados de
  acknowledges por usuário do grupo Monitor).
```


---

## Novidades (v1.1)

### Restricao de acesso: apenas Super Admin
A pagina, o item de menu (**NOC → Dashboard Executivo**) e o endpoint de dados
sao visiveis somente para usuarios do tipo **Super Admin**. Usuarios comuns nem
veem o menu.

### Painel "Analyst Performance"
Tabela com os analistas do grupo `Monitor` que trataram eventos no periodo:
- **Eventos**: quantos eventos distintos o analista tocou (acknowledge/mensagem).
- **Acoes**: total de acoes humanas do analista.
- **% do total**: participacao do analista no total de acoes humanas.

### Exportacao em PDF (real, com graficos)
Botao **Exportar PDF** no cabecalho. Captura o dashboard inteiro — incluindo os
graficos donut/gauge (`<canvas>`) — via `html2canvas` e gera um **PDF real** (A4
paisagem, paginado) que baixa automaticamente. Nome do arquivo:
`noc-executive_<tenant>_<data>.pdf`.

**Bibliotecas necessarias** (`html2canvas`, `jsPDF`): o modulo carrega primeiro os
arquivos locais em `assets/js/vendor/` e, se nao existirem, tenta o CDN jsDelivr
(so o navegador precisa de internet nesse caso — o servidor Zabbix nao). Para
funcionar **100% offline**, baixe os 2 arquivos conforme instrucoes em
`assets/js/vendor/README.md`.
