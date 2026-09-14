// TNSVT Stimulus bootstrap.
// Initialises the Stimulus app via @symfony/stimulus-bundle.
// All controllers in assets/controllers/ are auto-discovered by asset-map:compile.
//
// Turbo Drive (P10 / F10 commit 3): intercepted navigations remain as
// full-page loads for any link that opts out via data-turbo="false" or
// data-turbo-frame. Forms that POST to a controller with a symfony/turbo
// UxTurbo redirect will round-trip through Turbo instead of a 302 page
// reload. `csrf_protection_controller.js` already listens for
// `turbo:submit-start` / `turbo:submit-end` to inject the CSRF header.
import '@hotwired/turbo';
import { startStimulusApp } from '@symfony/stimulus-bundle';

startStimulusApp();
