import { browserSupportsWebAuthn, startAuthentication, startRegistration } from '@simplewebauthn/browser';

/**
 * Passkey ceremonies for Livewire components. Each takes the options JSON a
 * component returns and resolves to the response JSON to send back, or
 * null if the user cancelled or the browser refused.
 */
window.passkeys = {
    supported: () => browserSupportsWebAuthn(),

    async authenticate(optionsJson) {
        try {
            return JSON.stringify(await startAuthentication({ optionsJSON: JSON.parse(optionsJson) }));
        } catch (error) {
            console.warn('Passkey authentication did not complete', error);
            return null;
        }
    },

    async register(optionsJson) {
        try {
            return JSON.stringify(await startRegistration({ optionsJSON: JSON.parse(optionsJson) }));
        } catch (error) {
            console.warn('Passkey registration did not complete', error);
            return null;
        }
    },
};
