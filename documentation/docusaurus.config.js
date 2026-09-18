// @ts-check

const {
    createProjectDocsConfig,
} = require('@tu-cis-courses/docusaurus-preset/config');

module.exports = createProjectDocsConfig({
    siteDir: __dirname,
    tagline: 'Scaffold Project based CIS courses in minutes.',
});
