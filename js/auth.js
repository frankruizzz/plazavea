// Archivo: js/auth.js

document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');

    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const usuarioInput = document.getElementById('usuario').value.trim();
            const passwordInput = document.getElementById('password').value.trim();
            const turnoSelect = document.getElementById('turno').value.trim();

            if (!usuarioInput || !passwordInput || !turnoSelect) {
                alert('Por favor, rellene todos los campos del formulario.');
                return;
            }

            const formData = new FormData();
            formData.append('usuario', usuarioInput);
            formData.append('password', passwordInput);
            formData.append('id_turno', turnoSelect);

            try {
                // ✅ RUTA CORRECTA AL BACKEND
                const response = await fetch('/plazavea/php/login.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (response.ok && result.status === 'success') {
                    sessionStorage.setItem('sesion_activa', 'true');
                    sessionStorage.setItem('nombre_cajero', result.empleado);
                    sessionStorage.setItem('user', JSON.stringify(result.user));
                    
                    window.location.href = '/plazavea/pos.html';
                } else {
                    alert(result.message || 'Error al intentar iniciar sesión.');
                }

            } catch (error) {
                console.error('Error crítico en el flujo Fetch:', error);
                alert('No se pudo establecer comunicación con el servidor de Plaza Vea. Inténtelo más tarde.');
            }
        });
    }
});