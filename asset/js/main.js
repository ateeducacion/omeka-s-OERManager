import { readConfig } from './config.js';
import { initVisibility } from './ui/visibility.js';
import { initDrawer } from './ui/drawer.js';

const config = readConfig();
if (config) {
    initVisibility(config);
    initDrawer(config);
}
