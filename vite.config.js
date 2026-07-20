export default {
  build: {
    outDir: 'assets/dist',
    emptyOutDir: false,
    rollupOptions: {
      input: {
        frontend: 'assets/src/frontend/frontend.js'
      }
    }
  }
};
