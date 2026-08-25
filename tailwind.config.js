/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'selector',
  content: [
    "./assets/**/*.js",
    "./templates/**/*.html.twig",
  ],
  theme: {
    fontFamily: {
      'body': ['Inter', 'sans-serif'],
      'sans': ['Inter', 'sans-serif'],
      'heading': ['Outfit', 'sans-serif'],
    },
    extend: {
      colors: {
        'primary': {
          DEFAULT: '#3C7497',
          light: '#5CA6A5',
          dark: '#1e293b',
          red: '#CB2321',
        },
        'cech': {
          'red': '#CB2321',
          'red-dark': '#A81C1A',
          'red-light': '#FDF2F2',
          'teal': '#5CA6A5',
          'teal-dark': '#488584',
          'teal-light': '#F0F7F7',
          'blue': '#3C7497',
          'blue-dark': '#2E5B77',
          'blue-light': '#F0F5F9',
        },
      },
      spacing: {
        '55vw': '55vw'
      },
      container: {
        center: true,
        padding: {
          DEFAULT: '1rem',
          sm: '2rem',
        },
        screens: {
          sm: '600px',
          md: '728px',
          lg: '984px',
          xl: '1240px',
          '2xl': '1240px',
        },
      }
    },
  },
  plugins: [],
}
