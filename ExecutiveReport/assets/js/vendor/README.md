# Bibliotecas de terceiros (vendor) — exportação de PDF

O botão **Gerar PDF** do Executive Report usa duas bibliotecas JavaScript:

- **html2canvas** — captura o relatório (cards, tabelas, barras) como imagem.
- **jsPDF** — monta o arquivo PDF real e dispara o download.

O botão **NÃO** usa a impressão do navegador (`window.print()`): ele gera e
baixa um arquivo `.pdf` diretamente.

## Modo de carregamento (híbrido)

O módulo tenta carregar estas libs nesta ordem:

1. **Localmente** (offline): a partir desta pasta `assets/js/vendor/`, se os
   arquivos existirem.
2. **Via CDN** (fallback): se os arquivos locais não existirem, tenta baixar do
   jsDelivr. Isso requer que o **navegador** de quem abre o relatório tenha
   acesso à internet (o servidor Zabbix NÃO precisa de internet).

## Para funcionar 100% OFFLINE (recomendado)

Baixe os dois arquivos abaixo e salve nesta pasta com EXATAMENTE estes nomes:

| Arquivo esperado     | Baixe de |
|----------------------|----------|
| `html2canvas.min.js` | https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js |
| `jspdf.umd.min.js`   | https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js |

Exemplo (rode em qualquer máquina com internet e depois copie para o servidor):

```bash
cd assets/js/vendor
curl -L -o html2canvas.min.js https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js
curl -L -o jspdf.umd.min.js  https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js
```

Depois de copiar os arquivos, o export funciona sem qualquer acesso à internet.

## Versões testadas

- html2canvas 1.4.1
- jsPDF 2.5.2 (build UMD: expõe `window.jspdf.jsPDF`)
