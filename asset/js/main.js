import { readConfig } from './config.js';
import { initVisibility } from './ui/visibility.js';
import { initDrawer } from './ui/drawer.js';
import { initDrawerDetails } from './ui/drawerDetails.js';
import { initTermPicker } from './ui/termPicker.js';
import { initRecatalog } from './ui/recatalog.js';
import { initGovernance } from './ui/governance.js';
import { initAiPropose } from './ui/aiPropose.js';
import { initSearchForm } from './ui/searchForm.js';
import { initWorkflowDrawer } from './ui/workflowDrawer.js';

const config = readConfig();
if (config) {
    initVisibility(config);
    initDrawer(config);
    initDrawerDetails(config);
    initTermPicker(config);
    initRecatalog(config);
    // Same slot pattern as initRecatalog above: `governance.js` subscribes to
    // `drawerDetails.js`'s `GOVERNANCE_SLOT` event and never receives `config`
    // — it has no page-level data to read (see its file docblock).
    initGovernance();
    initAiPropose(config);
    initSearchForm();
    initWorkflowDrawer();
}
