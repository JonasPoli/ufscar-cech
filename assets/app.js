//import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
//import './styles/app.css';

//////////////////////
// switch light/dark mode
function setColorTheme(){
    if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark')
        document.documentElement.classList.add('sl-theme-dark')
    } else {
        document.documentElement.classList.remove('dark')
        document.documentElement.classList.remove('sl-theme-dark')
    }
}

window.darkMode=()=>{
    localStorage.theme = 'dark';
    setColorTheme();
}

window.lightMode=()=>{
    localStorage.theme = 'light';
    setColorTheme();
}

setColorTheme();
///////////////////
