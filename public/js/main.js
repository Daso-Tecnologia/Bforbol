/**
 * js/main.js
 * Helpers globais e controle de sessão.
 */

const auth = {
    verificar() {
        const token = api.getToken();
        if (!token) {
            location.href = 'login.html';
            return null;
        }

        // Exibir nome do usuário se disponível
        const stored = localStorage.getItem('boleto_usuario');
        if (stored) {
            try {
                const u = JSON.parse(stored);
                const el = document.getElementById('userNome');
                if (el) el.textContent = u.nome + ' (' + u.email + ')';
            } catch (e) {}
        }

        return token;
    },

    sair() {
        api.clearToken();
        localStorage.removeItem('boleto_usuario');
        location.href = 'login.html';
    },
};
window.auth = auth;

// Função global de logout (usada via onclick inline nas páginas)
window.sair = function () {
    try { auth.sair(); } catch (e) { location.href = 'login.html'; }
};

// Botão logout global e link de relatórios
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('btnLogout');
    if (btn) {
        btn.addEventListener('click', () => auth.sair());
    }

    const btnRel = document.getElementById('btnRelatorios');
    if (btnRel) {
        btnRel.href = 'relatorios.html';
    }

    // Elementos exclusivos de administrador (ex.: link Config. E-mail)
    const stored = localStorage.getItem('boleto_usuario');
    let isAdmin = false;
    if (stored) {
        try { isAdmin = JSON.parse(stored).nivel === 'completo'; } catch (e) {}
    }
    document.querySelectorAll('.admin-only').forEach(el => {
        if (!isAdmin) el.style.display = 'none';
    });
});

// Debounce helper
function debounce(fn, ms = 300) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
}