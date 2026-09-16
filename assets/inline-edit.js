// assets/inline-edit.js
//
// Edição inline por duplo clique, reutilizável em qualquer tabela.
//
// Como usar: em cada <td> editável, adicione:
//   class="celula-editavel"
//   data-id="123"            (identificador do registro)
//   data-campo="quantidade"  (nome do campo, o backend decide o que fazer com ele)
//   data-valor-bruto="1234,56"  (valor no formato que o input deve mostrar)
//
// A página precisa definir, ANTES de incluir este script:
//   window.INLINE_EDIT_ENDPOINT = 'programacao.php'; (a própria página, ou outra)
//   window.INLINE_EDIT_ACAO = 'ajax_editar_campo';    (nome da ação esperada no POST)
//
// O endpoint deve responder JSON: {"ok": true, "exibido": "texto formatado"}
// ou {"ok": false, "erro": "mensagem"}.

(function () {
    function iniciarEdicao(celula) {
        if (celula.querySelector('input')) {
            return; // já está editando
        }

        const valorExibidoAntigo = celula.textContent;
        const valorBruto = celula.dataset.valorBruto ?? valorExibidoAntigo.trim();
        const alinhamento = celula.classList.contains('text-end') ? 'text-end' : '';

        celula.textContent = '';
        const input = document.createElement('input');
        input.type = 'text';
        input.value = valorBruto;
        input.className = 'form-control form-control-sm inline-edit-input ' + alinhamento;
        celula.appendChild(input);
        input.focus();
        input.select();

        let jaResolvido = false;

        function cancelar() {
            if (jaResolvido) return;
            jaResolvido = true;
            celula.textContent = valorExibidoAntigo;
        }

        function salvar() {
            if (jaResolvido) return;
            jaResolvido = true;

            const novoValor = input.value.trim();
            if (novoValor === valorBruto) {
                celula.textContent = valorExibidoAntigo;
                return;
            }

            celula.textContent = '';
            const carregando = document.createElement('span');
            carregando.className = 'text-muted small';
            carregando.textContent = 'Salvando...';
            celula.appendChild(carregando);

            const dados = new URLSearchParams();
            dados.set('acao', window.INLINE_EDIT_ACAO);
            dados.set('id', celula.dataset.id);
            dados.set('campo', celula.dataset.campo);
            dados.set('valor', novoValor);

            fetch(window.INLINE_EDIT_ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: dados.toString(),
            })
                .then((resposta) => resposta.json())
                .then((json) => {
                    if (json.ok) {
                        celula.textContent = json.exibido;
                        celula.dataset.valorBruto = novoValor;
                        celula.classList.add('inline-edit-sucesso');
                        setTimeout(() => celula.classList.remove('inline-edit-sucesso'), 900);
                    } else {
                        celula.textContent = valorExibidoAntigo;
                        alert(json.erro || 'Não foi possível salvar.');
                    }
                })
                .catch(() => {
                    celula.textContent = valorExibidoAntigo;
                    alert('Erro de conexão ao salvar. Tente de novo.');
                });
        }

        input.addEventListener('blur', salvar);
        input.addEventListener('keydown', (evento) => {
            if (evento.key === 'Enter') {
                evento.preventDefault();
                input.blur();
            } else if (evento.key === 'Escape') {
                evento.preventDefault();
                jaResolvido = true; // evita o blur disparar salvar()
                celula.textContent = valorExibidoAntigo;
            }
        });
    }

    document.addEventListener('dblclick', (evento) => {
        const celula = evento.target.closest('.celula-editavel');
        if (celula) {
            iniciarEdicao(celula);
        }
    });
})();
