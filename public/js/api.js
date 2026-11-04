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