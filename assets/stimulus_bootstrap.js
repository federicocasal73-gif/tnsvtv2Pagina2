// TNSVT Stimulus bootstrap (shim).
// The original @symfony/stimulus-bundle entry triggered a 404 in the importmap
// because the bundle is not registered in importmap.php. We keep the file
// present so app.js imports resolve, but the actual Stimulus runtime is no
// longer initialised here. Pages that require interactive controllers can
// opt-in by importing the real Stimulus bundle once the asset_mapper
// configuration is fixed upstream.
// eslint-disable-next-line no-console
console.info('[TNSVT] Stimulus bootstrap skipped (no controllers registered).');
