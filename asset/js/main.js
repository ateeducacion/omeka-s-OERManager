import { readConfig } from './config.js';
import { initVisibility } from './ui/visibility.js';
import { initDrawer } from './ui/drawer.js';
import { initDrawerDetails } from './ui/drawerDetails.js';
import { initTermPicker } from './ui/termPicker.js';
import { initRecatalog } from './ui/recatalog.js';
import { initAiPropose } from './ui/aiPropose.js';
import { initSearchForm } from './ui/searchForm.js';

const config = readConfig();
if (config) {
    initVisibility(config);
    initDrawer(config);
    initDrawerDetails(config);
    initTermPicker(config);
    initRecatalog(config);
    initAiPropose(config);
    initSearchForm();
}
