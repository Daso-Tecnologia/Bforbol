/**
 * js/api.js
 * Cliente HTTP para a API do Sistema de Boletos.
 * Gerencia o token JWT e os erros das requisições.
 */

const api = (() => {
    const TOKEN_KEY = 'boleto_token';
    window.api = null; // placeholder (garante escopo global antes do fim do IIFE)

    // Calcula o caminho base do app (ex.: /php_boletos/) a partir da URL atual.
    // A página fica em <base>/public/<arquivo>, então a API fica em <base>/api/.
    function apiBase() {
        const path = location.pathname;
        const idx = path.lastIndexOf('/');
        const dir = idx >= 0 ? path.slice(0, idx + 1) : '/';
        return dir.replace(/public\/$/, '');
    }

    function apiUrl(url) {
        return apiBase() + url.replace(/^\//, '');
    }

    function getToken() {
        return localStorage.getItem(TOKEN_KEY);
    }

    function setToken(token) {
        localStorage.setItem(TOKEN_KEY, token);
    }

    function clearToken() {
        localStorage.removeItem(TOKEN_KEY);
    }

    function headers(isForm = false) {
        const h = {};
        const token = getToken();
        if (token) h['Authorization'] = 'Bearer ' + token;
        h['X-Auth-Token'] = token || '';
        if (!isForm) h['Content-Type'] = 'application/json';
        h['Accept'] = 'application/json';
        return h;
    }

    async function request(url, options = {}) {
        const res = await fetch(apiUrl(url), options);

        const contentType = res.headers.get('content-type') || '';
        let data;

        if (contentType.includes('application/json')) {
            const text = await res.text();
            if (text.trim() === '') {
                data = {};
            } else {
                try { data = JSON.parse(text); } catch { data = { raw: text }; }
            }
        } else {
            const text = await res.text();
            if (!res.ok) {
                try { data = JSON.parse(text); } catch { data = { error: text }; }
            } else {
                return { _download: true, _res: res, _text: text };
            }
        }

        if (!res.ok) {
            const err = new Error(data.error || (data.message ? data.message : 'Erro na requisição'));
            err.status = res.status;
            err.data = data;
            if (res.status === 401) {
                clearToken();
                if (!location.pathname.endsWith('login.html')) {
                    location.href = 'login.html';
                }
            }
            throw err;
        }

        return data;
    }

    return {
        getToken,
        setToken,
        clearToken,

        async get(url) {
            return request(url, { method: 'GET', headers: headers() });
        },

        async post(url, body = {}) {
            return request(url, {
                method: 'POST',
                headers: headers(false),
                body: JSON.stringify(body),
            });
        },

        async upload(url, formData) {
            return request(url, {
                method: 'POST',
                headers: headers(true),
                body: formData,
            });
        },

        /**
         * Envia TODOS os pendentes em várias chamadas curtas (cada uma < 60 s, o limite
         * do balanceador/Apache). Repete até o servidor dizer "concluido".
         * onProgresso({enviados, falhas, restantes}) é chamado a cada parte.
         */
        async enviarPendentes(onProgresso) {
            let aposId = 0, enviados = 0, falhas = 0, partes = 0;
            const detalhesFalhas = [];
            for (;;) {
                const d = await this.post('/api/envios/enviar_todos.php', { apos_id: aposId });
                partes++;
                enviados += d.enviados || 0;
                falhas += d.falhas || 0;
                (d.resultados || []).filter(r => !r.ok).forEach(r => detalhesFalhas.push('#' + r.id + ': ' + r.mensagem));
                if (typeof onProgresso === 'function') onProgresso({ enviados, falhas, restantes: d.restantes || 0 });
                if (d.concluido || !(d.ultimo_id > aposId) || partes >= 500) break;
                aposId = d.ultimo_id;
            }
            return { enviados, falhas, detalhesFalhas };
        },

        /** Reenvia os ids dados em várias chamadas curtas (< 60 s cada). */
        async reenviarLote(ids, onProgresso) {
            let fila = ids.slice(), reenviados = 0, falhas = 0, partes = 0;
            const detalhesFalhas = [];
            while (fila.length > 0 && partes < 500) {
                const d = await this.post('/api/boletos/reenviar_lote.php', { ids: fila });
                partes++;
                reenviados += d.reenviados || 0;
                falhas += d.falhas || 0;
                (d.resultados || []).filter(r => !r.ok).forEach(r => detalhesFalhas.push('#' + r.id + ': ' + r.mensagem));
                const sobra = d.nao_processados || [];
                if (typeof onProgresso === 'function') onProgresso({ reenviados, falhas, restantes: sobra.length });
                if (sobra.length >= fila.length) break; // sem progresso: evita laço infinito
                fila = sobra;
            }
            return { reenviados, falhas, detalhesFalhas };
        },

        /**
         * Lê a pasta de entrada em partes (cada chamada < 60 s). Junta os resultados.
         */
        async lerBoletos(onProgresso) {
            let cursor = null, partes = 0, lotes = 0, examinados = 0;
            const idsSessao = [];
            const acc = { sem_fornecedor: [], nao_ativos: [], nao_wg_ven: [], gate_indisponivel: [], sem_email: [], acima_limite: [], duplicados: [], avisos_cadastro: [] };
            for (;;) {
                const d = await this.post('/api/boletos/ler.php', { apos_arquivo: cursor, ids_sessao: idsSessao });
                partes++;
                lotes += d.lotes_criados || 0;
                examinados += d.examinados || 0;
                (d.ids_criados || []).forEach(i => idsSessao.push(i));
                Object.keys(acc).forEach(k => { acc[k] = acc[k].concat(d[k] || []); });
                if (typeof onProgresso === 'function') onProgresso({ examinados, lotes });
                if (!d.incompleto || !d.ultimo_arquivo || d.ultimo_arquivo === cursor || partes >= 500) break;
                cursor = d.ultimo_arquivo;
            }
            return Object.assign({ lotes_criados: lotes, examinados }, acc);
        },

        /**
         * Sincroniza fornecedores com o GATE em partes (< 60 s cada). Junta os resultados.
         * escopo: 'sem_email' (só os sem e-mail principal) ou 'todos' (compara todos os ativos).
         */
        async sincronizarGate(escopo, onProgresso) {
            let aposId = 0, partes = 0, verificados = 0, gateIndisponivel = false;
            const acc = { completados: [], gate_sem_email: [], email_invalido: [], nao_encontrados: [], divergencias: [], nomes_diferentes: [] };
            for (;;) {
                const d = await this.post('/api/fornecedores/sincronizar_gate.php', { escopo, apos_id: aposId });
                partes++;
                verificados += d.verificados || 0;
                Object.keys(acc).forEach(k => { acc[k] = acc[k].concat(d[k] || []); });
                if (typeof onProgresso === 'function') onProgresso({ verificados });
                if (d.gate_indisponivel) { gateIndisponivel = true; break; }
                if (d.concluido || !(d.ultimo_id > aposId) || partes >= 500) break;
                aposId = d.ultimo_id;
            }
            return Object.assign({ verificados, gate_indisponivel: gateIndisponivel }, acc);
        },

        async download(url) {
            const res = await fetch(apiUrl(url), { method: 'GET', headers: headers() });
            if (!res.ok) {
                let data;
                try { data = await res.json(); } catch { data = { error: 'Erro no download' }; }
                throw new Error(data.error || 'Erro no download');
            }

            // Extrair nome do arquivo do header Content-Disposition
            const cd = res.headers.get('content-disposition') || '';
            let nome = 'download';
            const m = cd.match(/filename="?([^"]+)"?/);
            if (m) nome = m[1];

            const blob = await res.blob();
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = nome;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(link.href);
        },
    };
})();

window.api = api;