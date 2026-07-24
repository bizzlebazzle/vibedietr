import './bootstrap';
import './scanner.js';

const resolvePreferredTheme = () => {
    const savedTheme = window.localStorage.getItem('theme');
    const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    return savedTheme === 'dark' || (savedTheme === null && systemPrefersDark);
};

const applyThemePreference = () => {
    document.documentElement.classList.toggle('dark', resolvePreferredTheme());
};

window.resolvePreferredTheme = resolvePreferredTheme;
window.applyThemePreference = applyThemePreference;

applyThemePreference();

document.addEventListener('livewire:navigated', applyThemePreference);

const darkModeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
const handleSystemThemeChange = () => {
    if (window.localStorage.getItem('theme') === null) {
        applyThemePreference();
    }
};

if (darkModeMediaQuery.addEventListener) {
    darkModeMediaQuery.addEventListener('change', handleSystemThemeChange);
} else {
    darkModeMediaQuery.addListener(handleSystemThemeChange);
}
