import { readConfig } from './config.js';
import { initVisibility } from './ui/visibility.js';
import { initDrawer } from './ui/drawer.js';
import { initTermPicker } from './ui/termPicker.js';
import { initRecatalog } from './ui/recatalog.js';

const config = readConfig();
if (config) {
    initVisibility(config);
    initDrawer(config);
    initTermPicker(config);
    initRecatalog(config);
}
