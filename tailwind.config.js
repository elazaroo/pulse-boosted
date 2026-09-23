const defaultTheme = require('tailwindcss/defaultTheme')
const colors = require('tailwindcss/colors')

/** @type {import('tailwindcss').Config} */
module.exports = {
    content: ["./resources/views/**/*.blade.php"],
    safelist: [
        {
            pattern: /grid-cols-(\d+)/,
            variants: ['sm', 'md', 'lg', 'xl', '2xl', 'default', 'default:lg'],
        },
        {
            pattern: /(row|col)-span-(\d+|full)/,
            variants: ['sm', 'md', 'lg', 'xl', '2xl', 'default', 'default:lg'],
        },
        {
            pattern: /h-\d+/,
            variants: ['sm', 'md', 'lg', 'xl', '2xl'],
        }
    ],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                // Zinc reads cooler and denser than Tailwind's default gray,
                // which suits a screen that is mostly numbers.
                gray: colors.zinc,
                accent: colors.indigo,
            },
            fontFamily: {
                'sans': ['Inter', ...defaultTheme.fontFamily.sans],
                'mono': ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            height: {
                '128': '32rem',
            }
        },
    },
    plugins: [
        require("@tailwindcss/forms"),
        require("@tailwindcss/container-queries"),
        function ({ addVariant }) {
            addVariant('default', 'html :where(&)')
            addVariant('scrollbar', '&::-webkit-scrollbar')
            addVariant('scrollbar-track', '&::-webkit-scrollbar-track')
            addVariant('scrollbar-thumb', '&::-webkit-scrollbar-thumb')
        },
    ],
};
