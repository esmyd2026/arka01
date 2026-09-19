import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import './styles.css';
import { purgeLegacyPlaintextToken } from './services/auth';
import { initializeTheme } from './services/theme';

purgeLegacyPlaintextToken();
initializeTheme().finally(() => createApp(App).use(router).mount('#app'));
