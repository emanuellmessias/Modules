# ExecutiveReport v0.4

## Feito nesta rodada
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
