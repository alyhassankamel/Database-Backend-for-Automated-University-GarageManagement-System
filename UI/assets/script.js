/* =============================================
   EUI PARKING SYSTEM - JavaScript
   ============================================= */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all functions
    initLoadingScreen();
    initMobileMenu();
    initPasswordToggle();
    initFormValidation();
    initAnimateOnScroll();
    initTableSearch();
    initTooltips();
    initConfirmActions();
    initToastNotifications();
    initCounterAnimation();
    initSmoothScroll();
});

/* =============================================
   LOADING SCREEN
   ============================================= */
function initLoadingScreen() {
    const loadingScreen = document.querySelector('.loading-screen');
    if (loadingScreen) {
        window.addEventListener('load', function() {
            setTimeout(function() {
                loadingScreen.classList.add('hidden');
            }, 800);
        });
    }
}

/* =============================================
   MOBILE MENU
   ============================================= */
function initMobileMenu() {
    const menuToggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    
    if (menuToggle && navLinks) {
        menuToggle.addEventListener('click', function() {
            navLinks.classList.toggle('active');
            
            // Animate hamburger icon
            const spans = menuToggle.querySelectorAll('span');
            spans.forEach((span, index) => {
                span.style.transform = navLinks.classList.contains('active') 
                    ? index === 1 ? 'scale(0)' : `rotate(${index === 0 ? 45 : -45}deg) translateY(${index === 0 ? 8 : -8}px)`
                    : 'none';
            });
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!menuToggle.contains(e.target) && !navLinks.contains(e.target)) {
                navLinks.classList.remove('active');
            }
        });
    }
}

/* =============================================
   PASSWORD TOGGLE
   ============================================= */
function initPasswordToggle() {
    const toggleButtons = document.querySelectorAll('.password-toggle');
    
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const input = this.previousElementSibling;
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️' : '🙈';
        });
    });
}

/* =============================================
   FORM VALIDATION
   ============================================= */
function initFormValidation() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input[required], select[required]');
        
        inputs.forEach(input => {
            // Real-time validation
            input.addEventListener('blur', function() {
                validateInput(this);
            });
            
            input.addEventListener('input', function() {
                if (this.classList.contains('error')) {
                    validateInput(this);
                }
            });
        });
        
        // Form submit
        form.addEventListener('submit', function(e) {
            let isValid = true;
            
            inputs.forEach(input => {
                if (!validateInput(input)) {
                    isValid = false;
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                showToast('Please fill in all required fields correctly', 'error');
            } else {
                // Add loading state to button
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.classList.add('btn-loading');
                    submitBtn.disabled = true;
                }
            }
        });
    });
}

function validateInput(input) {
    const value = input.value.trim();
    let isValid = true;
    
    // Remove existing error
    input.classList.remove('error');
    removeErrorMessage(input);
    
    // Check if empty
    if (value === '') {
        isValid = false;
        showInputError(input, 'This field is required');
    }
    // Email validation
    else if (input.type === 'email' && !isValidEmail(value)) {
        isValid = false;
        showInputError(input, 'Please enter a valid email');
    }
    // Phone validation
    else if (input.name === 'phone' && !isValidPhone(value)) {
        isValid = false;
        showInputError(input, 'Please enter a valid phone number');
    }
    // Password validation
    else if (input.type === 'password' && value.length < 6) {
        isValid = false;
        showInputError(input, 'Password must be at least 6 characters');
    }
    
    return isValid;
}

function showInputError(input, message) {
    input.classList.add('error');
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'input-error';
    errorDiv.style.cssText = 'color: #dc3545; font-size: 0.85rem; margin-top: 5px;';
    errorDiv.textContent = message;
    
    input.parentNode.appendChild(errorDiv);
}

function removeErrorMessage(input) {
    const existingError = input.parentNode.querySelector('.input-error');
    if (existingError) {
        existingError.remove();
    }
}

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function isValidPhone(phone) {
    return /^[0-9]{10,15}$/.test(phone.replace(/[\s-]/g, ''));
}

/* =============================================
   ANIMATE ON SCROLL
   ============================================= */
function initAnimateOnScroll() {
    const animatedElements = document.querySelectorAll('.card, .table-container, .auth-box');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }, index * 100);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'all 0.6s ease';
        observer.observe(el);
    });
}

/* =============================================
   TABLE SEARCH
   ============================================= */
function initTableSearch() {
    const searchInput = document.querySelector('.table-search input');
    const table = document.querySelector('table');
    
    if (searchInput && table) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
                
                // Highlight animation
                if (text.includes(searchTerm) && searchTerm !== '') {
                    row.style.background = 'rgba(42, 157, 143, 0.1)';
                    setTimeout(() => {
                        row.style.background = '';
                    }, 500);
                }
            });
        });
    }
}

/* =============================================
   TOOLTIPS
   ============================================= */
function initTooltips() {
    const tooltipElements = document.querySelectorAll('[data-tooltip]');
    
    tooltipElements.forEach(el => {
        el.addEventListener('mouseenter', function(e) {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = this.getAttribute('data-tooltip');
            tooltip.style.cssText = `
                position: absolute;
                background: var(--dark-blue);
                color: white;
                padding: 8px 12px;
                border-radius: 8px;
                font-size: 0.85rem;
                z-index: 1000;
                animation: fadeIn 0.3s ease;
            `;
            
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            tooltip.style.top = (rect.top - tooltip.offsetHeight - 10 + window.scrollY) + 'px';
            tooltip.style.left = (rect.left + rect.width/2 - tooltip.offsetWidth/2) + 'px';
            
            this._tooltip = tooltip;
        });
        
        el.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                this._tooltip.remove();
            }
        });
    });
}

/* =============================================
   CONFIRM ACTIONS
   ============================================= */
function initConfirmActions() {
    const confirmLinks = document.querySelectorAll('[data-confirm]');
    
    confirmLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const message = this.getAttribute('data-confirm') || 'Are you sure?';
            const href = this.getAttribute('href');
            
            showConfirmModal(message, () => {
                window.location.href = href;
            });
        });
    });
}

function showConfirmModal(message, onConfirm) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay active';
    overlay.innerHTML = `
        <div class="modal">
            <div class="modal-header">
                <h3>⚠️ Confirm Action</h3>
                <button class="modal-close">&times;</button>
            </div>
            <p style="margin-bottom: 25px; color: var(--gray);">${message}</p>
            <div style="display: flex; gap: 15px;">
                <button class="btn btn-outline modal-cancel" style="flex:1;">Cancel</button>
                <button class="btn btn-danger modal-confirm" style="flex:1;">Confirm</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    
    // Close handlers
    overlay.querySelector('.modal-close').addEventListener('click', () => overlay.remove());
    overlay.querySelector('.modal-cancel').addEventListener('click', () => overlay.remove());
    overlay.querySelector('.modal-confirm').addEventListener('click', () => {
        overlay.remove();
        onConfirm();
    });
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) overlay.remove();
    });
}

/* =============================================
   TOAST NOTIFICATIONS
   ============================================= */
function initToastNotifications() {
    // Create toast container if not exists
    if (!document.querySelector('.toast-container')) {
        const container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
}

function showToast(message, type = 'info', duration = 3000) {
    const container = document.querySelector('.toast-container');
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };
    
    toast.innerHTML = `
        <span style="font-size: 1.2rem;">${icons[type]}</span>
        <span>${message}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 400);
    }, duration);
}

// Make showToast globally available
window.showToast = showToast;

/* =============================================
   COUNTER ANIMATION
   ============================================= */
function initCounterAnimation() {
    const counters = document.querySelectorAll('.card-value, .stat-item .number');
    
    counters.forEach(counter => {
        const target = parseInt(counter.textContent);
        if (isNaN(target)) return;
        
        counter.textContent = '0';
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounter(counter, target);
                    observer.unobserve(entry.target);
                }
            });
        });
        
        observer.observe(counter);
    });
}

function animateCounter(element, target) {
    let current = 0;
    const increment = target / 50;
    const duration = 1000;
    const stepTime = duration / 50;
    
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            element.textContent = target;
            clearInterval(timer);
        } else {
            element.textContent = Math.floor(current);
        }
    }, stepTime);
}

/* =============================================
   SMOOTH SCROLL
   ============================================= */
function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
}

/* =============================================
   USER TYPE ANIMATION
   ============================================= */
document.querySelectorAll('.user-type-option input').forEach(input => {
    input.addEventListener('change', function() {
        // Remove animation class from all
        document.querySelectorAll('.user-type-option label').forEach(label => {
            label.style.transform = 'scale(1)';
        });
        
        // Add animation to selected
        if (this.checked) {
            const label = this.nextElementSibling;
            label.style.transform = 'scale(1.02)';
        }
    });
});

/* =============================================
   RIPPLE EFFECT FOR BUTTONS
   ============================================= */
document.querySelectorAll('.btn').forEach(button => {
    button.addEventListener('click', function(e) {
        const ripple = document.createElement('span');
        const rect = this.getBoundingClientRect();
        
        ripple.style.cssText = `
            position: absolute;
            background: rgba(255,255,255,0.5);
            border-radius: 50%;
            pointer-events: none;
            transform: scale(0);
            animation: ripple 0.6s ease-out;
            left: ${e.clientX - rect.left}px;
            top: ${e.clientY - rect.top}px;
            width: 100px;
            height: 100px;
            margin-left: -50px;
            margin-top: -50px;
        `;
        
        this.style.position = 'relative';
        this.style.overflow = 'hidden';
        this.appendChild(ripple);
        
        setTimeout(() => ripple.remove(), 600);
    });
});

// Add ripple animation
const style = document.createElement('style');
style.textContent = `
    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

/* =============================================
   AUTO HIDE ALERTS
   ============================================= */
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.opacity = '0';
        alert.style.transform = 'translateX(100%)';
        setTimeout(() => alert.remove(), 400);
    }, 5000);
});