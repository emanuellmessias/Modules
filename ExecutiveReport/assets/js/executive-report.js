/**
 * Executive Report - exportação de PDF.
 *
 * Captura o conteúdo do relatório com html2canvas e monta um PDF real
 * (A4 retrato, multipágina) via jsPDF, disparando o download automaticamente.
 * NÃO usa window.print() / diálogo de impressão do navegador.
 *
 * Requer window.html2canvas e window.jspdf (carregados local ou via CDN).
 */
window.ExecutiveReportPdf = (function () {
	'use strict';

	/**
	 * Liga o botão de export ao gerador de PDF.
	 */
	function init() {
		var btn = document.getElementById('er-export-pdf');
		if (btn) {
			btn.addEventListener('click', exportPdf);
		}
	}

	function exportPdf() {
		var btn = document.getElementById('er-export-pdf');

		// Container que embrulha o relatório inteiro (ver report.view.php).
		var target = document.querySelector('.wrapper')
			|| document.querySelector('main')
			|| document.body;

		var h2c = window.html2canvas;
		var jsPdfNs = (window.jspdf || window.jsPDF) ? (window.jspdf || window) : null;

		if (typeof h2c !== 'function' || !jsPdfNs || !jsPdfNs.jsPDF) {
			alert('Bibliotecas de exportação (html2canvas / jsPDF) não carregaram. '
				+ 'Verifique a pasta assets/js/vendor/ do módulo ou o acesso ao CDN pelo navegador.');
			return;
		}
		var JsPDF = jsPdfNs.jsPDF;

		var originalLabel = btn ? btn.textContent : '';
		if (btn) {
			btn.disabled = true;
			btn.textContent = 'Gerando PDF…';
		}

		// Esconde elementos marcados como "não imprimir" durante a captura.
		var hidden = [];
		Array.prototype.forEach.call(document.querySelectorAll('.er-no-print'), function (el) {
			hidden.push([el, el.style.display]);
			el.style.display = 'none';
		});

		var opts = {backgroundColor: '#1f1f1f', scale: 2, useCORS: true, logging: false};

		h2c(target, opts)
			.then(function (canvas) {
				var pdf = new JsPDF({orientation: 'portrait', unit: 'pt', format: 'a4'});
				var pageW = pdf.internal.pageSize.getWidth();
				var pageH = pdf.internal.pageSize.getHeight();
				var margin = 20;
				var usableW = pageW - margin * 2;

				var imgW = usableW;
				var imgH = (canvas.height * imgW) / canvas.width;
				var imgData = canvas.toDataURL('image/png');

				// Paginação: fatia verticalmente a imagem por página.
				var pageContentH = pageH - margin * 2;
				var renderedH = 0;
				var first = true;

				while (renderedH < imgH) {
					if (!first) {
						pdf.addPage();
					}
					pdf.addImage(imgData, 'PNG', margin, margin - renderedH, imgW, imgH);

					// Cobre o overflow da parte de baixo da página com um retângulo
					// da cor de fundo, para não "vazar" conteúdo da próxima seção.
					renderedH += pageContentH;
					first = false;
				}

				var stamp = new Date().toISOString().slice(0, 16).replace('T', '_').replace(':', 'h');
				pdf.save('executive-report_' + stamp + '.pdf');
			})
			.catch(function (err) {
				console.error('Executive Report: falha ao gerar PDF', err);
				alert('Falha ao gerar o PDF: ' + err.message);
			})
			.finally(function () {
				// Restaura os elementos escondidos.
				hidden.forEach(function (pair) {
					pair[0].style.display = pair[1];
				});
				if (btn) {
					btn.disabled = false;
					btn.textContent = originalLabel || 'Gerar PDF';
				}
			});
	}

	return {init: init, exportPdf: exportPdf};
})();
