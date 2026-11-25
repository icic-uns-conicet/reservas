import { existsSync, mkdirSync, renameSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [
        vue(),
        {
            name: 'nextcloud-css-output',
            closeBundle() {
                const builtCss = resolve('js/reservas.css')
                const cssDirectory = resolve('css')

                if (existsSync(builtCss)) {
                    mkdirSync(cssDirectory, { recursive: true })
                    renameSync(builtCss, resolve(cssDirectory, 'reservas.css'))
                }
            },
        },
    ],

    build: {
        outDir: 'js',
        emptyOutDir: false,

        rollupOptions: {
            input: resolve('src/main.js'),

            output: {
                format: 'iife',
                name: 'Reservas',
                entryFileNames: 'reservas.js',
                assetFileNames: 'reservas.[ext]',
            },
        },
    },
})
