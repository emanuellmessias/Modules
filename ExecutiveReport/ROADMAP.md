# ExecutiveReport v0.6

## Feito nesta rodada (v0.6)
- **SLA agora é orientado a SEVERIDADE, não só a ping ICMP** (`classes/Sla.php`).
  Qualquer problema com severidade >= `SLA_MIN_SEVERITY` (padrão = Alta) conta
  como downtime, usando a mesma união de intervalos de antes. O objetivo é que
  o SLA responda "o serviço esteve disponível?" e não apenas "a máquina
  respondeu ping?". Um banco travado (Desastre) agora derruba o SLA mesmo com
  ICMP OK. Modo legado disponível via `Sla::SLA_MODE = 'icmp'`.
- A seção **"Indisponibilidade ICMP"** (`classes/Offenders.php`) foi mantida como
  camada separada e mais drástica (quedas totais de rede), agora com piso de
  severidade próprio (`Offenders::ICMP_MIN_SEVERITY`), independente do SLA.
- **Executive Score recalibrado** (`classes/ExecutiveScore.php`) para refletir a
  nova saúde do ambiente: penalidades com tetos por dimensão
  (Disponibilidade -40, Impactos -20, Hosts down -15, Críticos Alta+Desastre
  -25) e um `breakdown` retornado para transparência.
- Rótulos da view ajustados: KPI "SLA Disponibilidade", header de categoria
  "SLA", e nota do score explicando as dimensões. Nota adicionada na seção ICMP
  deixando claro que ela é a camada de quedas de rede.

## Feito em rodada anterior (v0.5)
- Corrigido bug na "Saúde do Ambiente" (`classes/Health.php`): o
  `problem.get` filtrava só `TRIGGER_SEVERITY_DISASTER`, então todas as
  severidades exceto Desastre vinham zeradas. Filtro removido — agora
  conta todas as severidades (Não classificada → Desastre), como o nome
  da seção e o Executive Score (que usa "high") sempre pressupuseram.
- A view agora exibe **todas** as severidades com valor > 0 (antes só
  Desastre), e o título da seção voltou a ser "Saúde do Ambiente".
- Corrigido `is_admin`: o controller (`actions/ReportView.php`) não
  enviava a flag para a view, então um Super Admin com apenas 1 cliente
  ficava travado no valor estático. Agora `is_admin` é calculado e
  enviado no response.
- Compatibilidade Zabbix 7.0: `Offenders` trocou `selectGroups` (removido
  no 7.0) por `selectHostGroups`, com fallback de leitura para a chave
  `groups`/`hostgroups`.
- Adicionado botão "Gerar PDF" (dispara `window.print()`), que o ROADMAP
  v0.4 prometia mas não existia no código. O CSS de impressão já estava
  presente.
- Removido `assets/css/executive.css`: era código morto/divergente que
  nunca era carregado (a view usa CSS inline). Extração continua como
  pendência abaixo.

## Feito em rodada anterior (v0.4)
- Corrigido bug de acentos sumindo (`actions/ReportView.php` e
  `classes/Statistics.php` estavam salvos em ISO-8859-1, causando
  `htmlspecialchars()` retornar string vazia nos rótulos acentuados).
  Strings de UI agora usam `\u{XXXX}` como blindagem extra.
- Filtro de período (7/15/30/90 dias) no topo, ao lado do cliente.
- SLA real calculado por categoria e SLA Global do cliente
  (`classes/Sla.php`), baseado no histórico de eventos de problema no
  período selecionado, com severidade mínima configurável
  (`Sla::SLA_MIN_SEVERITY`, hoje = Média).
- Resumo Geral em cards (visual mais executivo/dashboard).
- Severidades com valor 0 somem da tela automaticamente.
- Botão "Gerar PDF" (aciona o print do navegador) + CSS de impressão.

## Pendências / próximos passos
- Validar o cálculo de SLA com dados reais de produção (a lógica de
  união de intervalos evita contar downtime duplicado, mas vale
  conferir alguns casos manualmente antes de confiar 100%).
- Decidir se `Sla::SLA_MIN_SEVERITY` deve ser configurável por cliente
  (hoje é fixo em "Média" para todo mundo).
- Extrair o CSS inline da view para `assets/css/executive.css` quando
  o módulo crescer mais (hoje está inline pra simplificar).
- Avaliar se vale um botão de exportação em Excel/CSV além do PDF.
