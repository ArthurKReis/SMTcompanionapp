/* ------------------------------------------------------------------
   SMT1 · Administração de Party — CRUD de parties
   Avisos do jogo (nível acima do protagonista / limite do COMP) são
   mostrados em tempo real, mas nunca impedem o salvamento.
   ------------------------------------------------------------------ */
'use strict';

window.Parties = {
  lista: [],
  demonios: [],
  editandoId: null,
  pendenteExclusao: null,
  modal: null,
  confirmModal: null,

  init() {
    this.modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-party'));
    this.confirmModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-confirm'));

    $('#confirm-delete').on('click', () => {
      const id = this.pendenteExclusao;
      this.confirmModal.hide();
      if (id) this.excluir(id);
    });

    $('#btn-new-party').on('click', () => this.abrirFormulario(null));
    $('#form-party').on('submit', (ev) => { ev.preventDefault(); this.salvar(); });

    $('#party-slots').on('change', 'select', () => {
      this.atualizarOpcoesBloqueadas();
      this.atualizarAvisos();
    });
    $('#p-protagonist-level, #p-comp-upgraded').on('input change', () => this.atualizarAvisos());

    $(document).on('click', '.js-edit-party', function () {
      Parties.abrirFormulario(Number($(this).data('id')));
    });
    $(document).on('click', '.js-del-party', function () {
      Parties.pedirExclusao(Number($(this).data('id')), $(this).data('name'));
    });
  },

  /* ---------------- listagem ---------------- */

  async load() {
    $('#parties-list').html('<div class="col-12"><div class="empty-state">Carregando…</div></div>');

    try {
      const { data } = await api('GET', { rota: 'parties' });

      if (!data.length) {
        $('#parties-list').html(
          '<div class="col-12"><div class="empty-state">' +
          '<p class="mb-3">Nenhuma party salva ainda.</p>' +
          '<button class="btn btn-danger btn-sm" id="btn-empty-new">Criar a primeira party</button>' +
          '</div></div>'
        );
        $('#btn-empty-new').on('click', () => Parties.abrirFormulario(null));
        this.lista = [];
        return;
      }

      // a listagem já traz formação e avisos de cada party
      this.lista = data;
      this.render();
    } catch (e) {
      $('#parties-list').html('<div class="col-12"><div class="empty-state text-danger">' + esc(erroLegivel(e)) + '</div></div>');
    }
  },

  render() {
    const html = this.lista.map((p) => {
      const al = (p.protagonist_alignment || '').toLowerCase();
      const limite = p.comp_limit;
      const qtd = p.demon_count;

      const badges = [];
      if (p.protagonist_alignment) {
        badges.push('<span class="badge badge-soft al-' + esc(al) + '">' + esc(p.protagonist_alignment) + '</span>');
      }
      badges.push('<span class="badge ' + (qtd > limite ? 'badge-danger-soft' : 'badge-soft') + '">' +
        qtd + ' / ' + limite + ' demônios</span>');
      if (p.human_partners) {
        badges.push('<span class="badge badge-soft">humanos: ' + esc(p.human_partners) + '</span>');
      }
      const acimaNivel = (p.avisos.acima_do_nivel || []).length;
      if (acimaNivel > 0) {
        badges.push('<span class="badge badge-warn">' + acimaNivel + ' acima do nível</span>');
      }

      const slots = [];
      for (let i = 1; i <= 5; i++) {
        const d = p.demons.find((x) => x.slot === i);
        slots.push(
          '<div class="party-slot">' +
            '<span class="pos">' + i + '</span>' +
            (d
              ? '<span>' + esc(d.demon) + '</span><span class="ms-auto text-secondary small">' + esc(d.race) + ' · Nv ' + fmtLevel(d.demon_level) + '</span>'
              : '<span class="empty">—</span>') +
          '</div>'
        );
      }

      return (
        '<div class="col-12 col-md-6 col-xl-4">' +
          '<div class="card party-card">' +
            '<div class="card-body d-flex flex-column gap-2">' +
              '<div class="d-flex align-items-start gap-2">' +
                '<p class="party-name">' + esc(p.party) + '</p>' +
                '<div class="ms-auto text-end small text-secondary">' +
                  (p.protagonist_name ? esc(p.protagonist_name) : '—') +
                  '<br><span class="text-light">Nv ' + p.protagonist_level + '</span>' +
                '</div>' +
              '</div>' +
              '<div class="d-flex flex-wrap gap-1">' + badges.join('') + '</div>' +
              '<div class="party-slots">' + slots.join('') + '</div>' +
              (p.notes ? '<p class="small text-secondary mb-0">' + esc(p.notes) + '</p>' : '') +
              '<div class="d-flex gap-2 mt-1">' +
                '<button class="btn btn-sm btn-outline-light js-edit-party" data-id="' + p.id + '">Editar</button>' +
                '<button class="btn btn-sm btn-outline-danger js-del-party" data-id="' + p.id + '" data-name="' + esc(p.party) + '">Excluir</button>' +
              '</div>' +
            '</div>' +
          '</div>' +
        '</div>'
      );
    }).join('');

    $('#parties-list').html(html);
  },

  /* ---------------- formulário ---------------- */

  async carregarDemônios() {
    if (this.demonios.length) return this.demonios;
    const { data } = await api('GET', { rota: 'demons_lista' });
    this.demonios = data;
    return this.demonios;
  },

  montarSlots() {
    const opcoes = ['<option value="">— vazio —</option>'].concat(
      this.demonios.map((d) =>
        '<option value="' + d.id + '" data-name="' + esc(d.name) + '" data-level="' + d.level + '">' +
        esc(d.name) + ' · ' + esc(d.race) + ' · Nv ' + fmtLevel(d.level) +
        '</option>'
      )
    ).join('');

    let html = '';
    for (let i = 1; i <= 5; i++) {
      html +=
        '<div class="col-12 col-md-6">' +
          '<div class="slot-row">' +
            '<span class="slot-pos">' + i + '</span>' +
            '<select class="form-select form-select-sm slot-demon" data-slot="' + i + '">' + opcoes + '</select>' +
          '</div>' +
        '</div>';
    }
    $('#party-slots').html(html);
  },

  async abrirFormulario(id) {
    this.editandoId = id;

    $('#form-party')[0].reset();
    $('#form-party').removeClass('was-validated');
    $('#form-party .is-invalid').removeClass('is-invalid');
    $('#party-form-status').text('');
    $('#party-modal-title').text(id ? 'Editar party' : 'Nova party');

    try {
      await this.carregarDemônios();
    } catch (e) {
      toast('Não foi possível carregar a lista de demônios: ' + erroLegivel(e), 'error');
      return;
    }

    this.montarSlots();

    if (id) {
      try {
        const { data } = await api('GET', { rota: 'party', id: id });
        $('#p-name').val(data.party);
        $('#p-protagonist-name').val(data.protagonist_name || '');
        $('#p-protagonist-level').val(data.protagonist_level);
        $('#p-alignment').val(data.protagonist_alignment || '');
        $('#p-comp-upgraded').prop('checked', data.comp_upgraded === 1);
        $('#p-human-partners').val(data.human_partners || '');
        $('#p-notes').val(data.notes || '');
        data.demons.forEach((d) => {
          $('#party-slots select[data-slot="' + d.slot + '"]').val(String(d.demon_id));
        });
      } catch (e) {
        toast(erroLegivel(e), 'error');
        return;
      }
    }

    this.atualizarOpcoesBloqueadas();
    this.atualizarAvisos();
    this.modal.show();
  },

  /** Impede escolher o mesmo demônio em duas posições. */
  atualizarOpcoesBloqueadas() {
    const escolhidos = $('#party-slots select').map(function () { return $(this).val(); }).get().filter(Boolean);

    $('#party-slots select').each(function () {
      const atual = $(this).val();
      $(this).find('option').each(function () {
        const valor = $(this).val();
        if (!valor || valor === atual) return;
        $(this).prop('disabled', escolhidos.indexOf(valor) !== -1);
      });
    });
  },

  formacaoSelecionada() {
    const out = [];
    $('#party-slots select').each(function () {
      const id = Number($(this).val());
      if (!id) return;
      out.push({
        slot: Number($(this).data('slot')),
        demon_id: id,
        nome: $(this).find('option:selected').data('name'),
        nivel: Number($(this).find('option:selected').data('level')),
      });
    });
    return out;
  },

  atualizarAvisos() {
    const nivelHeroi = Number($('#p-protagonist-level').val()) || 1;
    const limite = $('#p-comp-upgraded').is(':checked') ? 4 : 3;
    const formacao = this.formacaoSelecionada();

    const acimaNivel = formacao.filter((d) => d.nivel > nivelHeroi);
    const excedido = formacao.length > limite;

    $('#party-counter').html(
      '<span class="badge ' + (excedido ? 'badge-danger-soft' : 'badge-soft') + '">' +
      formacao.length + ' / ' + limite + ' demônios</span>'
    );

    const avisos = [];
    if (excedido) {
      avisos.push(
        '<div class="alert alert-warning py-2 mb-2 small">O COMP permite ' + limite +
        ' demônio(s); a party tem ' + formacao.length + '. No jogo isso não seria possível (aviso, não bloqueio).</div>'
      );
    }
    if (acimaNivel.length) {
      avisos.push(
        '<div class="alert alert-warning py-2 mb-0 small">Nível acima do protagonista (' + nivelHeroi + '): ' +
        acimaNivel.map((d) => esc(d.nome) + ' (' + fmtLevel(d.nivel) + ')').join(', ') +
        '. No jogo você não comandaria esses demônios.</div>'
      );
    }

    $('#party-warnings').html(avisos.join(''));

    // marca visual nos selects
    $('#party-slots select').each(function () {
      const nivel = Number($(this).find('option:selected').data('level'));
      const tem = $(this).val() !== '' && nivel > nivelHeroi;
      $(this).toggleClass('is-over', tem);
    });
  },

  dadosDoFormulario() {
    return {
      name: $('#p-name').val().trim(),
      protagonist_name: $('#p-protagonist-name').val().trim() || null,
      protagonist_level: Number($('#p-protagonist-level').val()) || 1,
      protagonist_alignment: $('#p-alignment').val() || null,
      comp_upgraded: $('#p-comp-upgraded').is(':checked') ? 1 : 0,
      human_partners: $('#p-human-partners').val().trim() || null,
      notes: $('#p-notes').val().trim() || null,
    };
  },

  async salvar() {
    const dados = this.dadosDoFormulario();
    const formacao = this.formacaoSelecionada().map((d) => ({ slot: d.slot, demon_id: d.demon_id }));

    $('#form-party .is-invalid').removeClass('is-invalid');
    $('#party-form-status').text('Salvando…');
    $('#btn-save-party').prop('disabled', true);

    try {
      if (this.editandoId) {
        await api('PATCH', { rota: 'party', id: this.editandoId }, dados);
        await api('PUT', { rota: 'party_demons', id: this.editandoId }, { demons: formacao });
        toast('Party atualizada.');
      } else {
        await api('POST', { rota: 'parties' }, Object.assign({}, dados, { demons: formacao }));
        toast('Party criada.');
      }

      this.modal.hide();
      await this.load();
    } catch (erro) {
      if (erro.details && typeof erro.details === 'object') {
        Object.keys(erro.details).forEach((campo) => {
          const $input = $('#form-party [data-error="' + campo + '"]');
          const mapa = { name: '#p-name', protagonist_name: '#p-protagonist-name', protagonist_level: '#p-protagonist-level' };
          const seletor = mapa[campo];
          if (seletor) $(seletor).addClass('is-invalid');
          if ($input.length) $input.text(erro.details[campo]);
        });
      }
      $('#party-form-status').text('');
      toast(erroLegivel(erro), 'error');
    } finally {
      $('#btn-save-party').prop('disabled', false);
    }
  },

  /** Abre o modal de confirmação (no lugar do confirm() do navegador). */
  pedirExclusao(id, nome) {
    this.pendenteExclusao = id;
    $('#confirm-party-name').text(nome);
    this.confirmModal.show();
  },

  async excluir(id) {
    try {
      await api('DELETE', { rota: 'party', id: id });
      toast('Party excluída.');
      await this.load();
    } catch (e) {
      toast(erroLegivel(e), 'error');
    } finally {
      this.pendenteExclusao = null;
    }
  },
};
