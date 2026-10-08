import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	main: 'src/main.js',
	files: 'src/files.js',
}, {
	inlineCSS: { relativeCSSInjection: true },
	config: {
		build: {
			rollupOptions: {
				// files.js only registers a WebDAV property from @nextcloud/files/dav – without this,
				// the unused WebDAV client and its Node polyfills end up in every Files page
				treeshake: { moduleSideEffects: (id) => !/node_modules\/webdav\//.test(id) },
			},
		},
	},
})
