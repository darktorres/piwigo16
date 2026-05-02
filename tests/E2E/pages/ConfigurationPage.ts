/**
 * Configuration Page Object
 *
 * @remarks
 * Page object modeling the Piwigo configuration admin pages. Handles all configuration-related
 * UI elements across multiple configuration sections (main, watermark, sizes, email, comments, etc).
 * Provides methods for navigating between sections, getting/setting form values, and interacting
 * with configuration-specific controls.
 *
 * Supports sections: main, watermark, sizes, email, comments, display, default
 *
 * @packageDocumentation
 */

import { Page, Locator, expect } from '@playwright/test';
import { BasePage } from './BasePage';
import { ELEMENT_TIMEOUTS } from '../config/test-timeouts';

export class ConfigurationPage extends BasePage {
    readonly configForm: Locator;
    readonly submitButton: Locator;
    readonly cancelButton: Locator;
    readonly successMessage: Locator;
    readonly sectionTabs: Locator;
    readonly pwgToken: Locator;

    constructor(page: Page) {
        super(page);
        this.configForm = page.locator('form[method="post"]');
        this.submitButton = page.locator(
            'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
        );
        this.cancelButton = page.locator('button[name="cancel"], a[href*="cancel"]');
        this.successMessage = page.locator('text=Your configuration settings are saved');
        this.sectionTabs = page.locator('a[href*="section="]');
        this.pwgToken = page.locator('input[name="pwg_token"]');
    }

    /**
     * Navigate to a specific configuration section
     * @param sectionName - The section name (main, watermark, sizes, email, comments, display, default)
     */
    async navigateToSection(sectionName: string): Promise<void> {
        await this.navigate(`/admin.php?page=configuration&section=${sectionName}`);
        await this.waitForPageLoad();
        await this.configForm.waitFor({ state: 'visible', timeout: ELEMENT_TIMEOUTS.standard });
    }

    /**
     * Get the configuration form element
     */
    async getConfigForm(): Promise<Locator> {
        await expect(this.configForm).toBeVisible();
        return this.configForm;
    }

    /**
     * Get the submit button
     */
    async getSubmitButton(): Promise<Locator> {
        await expect(this.submitButton).toBeVisible();
        return this.submitButton;
    }

    /**
     * Get the cancel button
     */
    async getCancelButton(): Promise<Locator> {
        return this.cancelButton;
    }

    /**
     * Get the success message locator
     */
    async getSuccessMessage(): Promise<Locator> {
        return this.successMessage;
    }

    /**
     * Get the error message locator (inherited from BasePage but exposed for consistency)
     */
    async getErrorMessage(): Promise<Locator> {
        return this.errorMessage;
    }

    /**
     * Get all section tabs for navigation
     */
    async getSectionTabs(): Promise<Locator> {
        return this.sectionTabs;
    }

    /**
     * Click a specific section tab
     * @param sectionName - The section name to navigate to
     */
    async clickSection(sectionName: string): Promise<void> {
        const sectionLink = this.page.locator(`a[href*="section=${sectionName}"]`);
        await expect(sectionLink).toBeVisible();
        await sectionLink.click();
        await this.waitForPageLoad();
    }

    /**
     * Get a setting value by field name (text input, textarea, select, checkbox, radio)
     * @param name - The name attribute of the setting field
     * @returns The value of the setting
     */
    async getSetting(name: string): Promise<string | boolean | null> {
        // Try input/textarea first (returns value)
        const input = this.page.locator(`input[name="${name}"], textarea[name="${name}"]`);
        const inputCount = await input.count();
        if (inputCount > 0) {
            const inputType = await input.first().getAttribute('type');
            if (inputType === 'checkbox' || inputType === 'radio') {
                return await input.first().isChecked();
            }
            return await input.first().inputValue();
        }

        // Try select (returns selected value)
        const select = this.page.locator(`select[name="${name}"]`);
        const selectCount = await select.count();
        if (selectCount > 0) {
            return await select.inputValue();
        }

        return null;
    }

    /**
     * Set a setting value by field name (text input, textarea, select, checkbox, radio)
     * @param name - The name attribute of the setting field
     * @param value - The value to set
     */
    async setSetting(name: string, value: string | boolean): Promise<void> {
        // Try input/textarea first
        const input = this.page.locator(`input[name="${name}"], textarea[name="${name}"]`);
        const inputCount = await input.count();
        if (inputCount > 0) {
            const inputType = await input.first().getAttribute('type');
            if (inputType === 'checkbox' || inputType === 'radio') {
                const isChecked = await input.first().isChecked();
                const shouldBeChecked = typeof value === 'boolean' ? value : value === 'true';
                if (isChecked !== shouldBeChecked) {
                    await input.first().click();
                }
            } else {
                await input.first().fill(String(value));
            }
            return;
        }

        // Try select
        const select = this.page.locator(`select[name="${name}"]`);
        const selectCount = await select.count();
        if (selectCount > 0) {
            await select.selectOption(String(value));
            return;
        }

        throw new Error(`Setting field "${name}" not found`);
    }

    /**
     * Submit the configuration form and wait for success message
     * @param successText - Optional custom success message to wait for
     */
    async submitForm(successText: string = 'Your configuration settings are saved'): Promise<void> {
        await expect(this.submitButton).toBeVisible({ timeout: ELEMENT_TIMEOUTS.quick });
        await this.submitButton.click();

        const successMsg = this.page.locator(`text=${successText}`);
        await expect(successMsg).toBeVisible({ timeout: 5000 });
    }

    /**
     * Check if a setting exists
     * @param name - The name attribute of the setting field
     */
    async hasSetting(name: string): Promise<boolean> {
        const input = this.page.locator(`input[name="${name}"], textarea[name="${name}"]`);
        const select = this.page.locator(`select[name="${name}"]`);

        const inputCount = await input.count();
        const selectCount = await select.count();

        return inputCount > 0 || selectCount > 0;
    }

    /**
     * Get a text input field by name
     * @param name - The name attribute of the input
     */
    getTextInput(name: string): Locator {
        return this.page.locator(`input[name="${name}"], textarea[name="${name}"]`).first();
    }

    /**
     * Get a checkbox field by name
     * @param name - The name attribute of the checkbox
     */
    getCheckbox(name: string): Locator {
        return this.page.locator(`input[type="checkbox"][name="${name}"]`).first();
    }

    /**
     * Get a select field by name
     * @param name - The name attribute of the select
     */
    getSelect(name: string): Locator {
        return this.page.locator(`select[name="${name}"]`).first();
    }

    /**
     * Get a radio button by name and value
     * @param name - The name attribute of the radio
     * @param value - The value of the radio button
     */
    getRadio(name: string, value: string): Locator {
        return this.page.locator(`input[type="radio"][name="${name}"][value="${value}"]`).first();
    }

    /**
     * Toggle a checkbox by name
     * @param name - The name attribute of the checkbox
     */
    async toggleCheckbox(name: string): Promise<void> {
        const checkbox = this.getCheckbox(name);
        await expect(checkbox).toBeVisible();
        await checkbox.click();
    }

    /**
     * Check a checkbox by name (ensure it's checked)
     * @param name - The name attribute of the checkbox
     */
    async checkCheckbox(name: string): Promise<void> {
        const checkbox = this.getCheckbox(name);
        await expect(checkbox).toBeVisible();
        const isChecked = await checkbox.isChecked();
        if (!isChecked) {
            await checkbox.click();
        }
    }

    /**
     * Uncheck a checkbox by name (ensure it's unchecked)
     * @param name - The name attribute of the checkbox
     */
    async uncheckCheckbox(name: string): Promise<void> {
        const checkbox = this.getCheckbox(name);
        await expect(checkbox).toBeVisible();
        const isChecked = await checkbox.isChecked();
        if (isChecked) {
            await checkbox.click();
        }
    }

    /**
     * Select a radio button by name and value
     * @param name - The name attribute of the radio
     * @param value - The value of the radio button
     */
    async selectRadio(name: string, value: string): Promise<void> {
        const radio = this.getRadio(name, value);
        await expect(radio).toBeVisible();
        await radio.click();
    }

    /**
     * Get all options from a select field
     * @param name - The name attribute of the select
     */
    async getSelectOptions(name: string): Promise<string[]> {
        const select = this.getSelect(name);
        return await select.locator('option').allTextContents();
    }

    /**
     * Get all option values from a select field
     * @param name - The name attribute of the select
     */
    async getSelectOptionValues(name: string): Promise<string[]> {
        const select = this.getSelect(name);
        const options = await select.locator('option').all();
        const values: string[] = [];
        for (const option of options) {
            const value = await option.getAttribute('value');
            if (value !== null) {
                values.push(value);
            }
        }
        return values;
    }

    /**
     * Verify PWG token is present (CSRF protection)
     */
    async verifyPwgToken(): Promise<boolean> {
        try {
            await expect(this.pwgToken).toBeAttached({ timeout: ELEMENT_TIMEOUTS.quick });
            const tokenValue = await this.pwgToken.getAttribute('value');
            return tokenValue !== null && tokenValue.length > 10;
        } catch {
            return false;
        }
    }

    /**
     * Get the current section from the URL
     */
    getCurrentSection(): string | null {
        const url = this.page.url();
        const match = url.match(/section=(\w+)/);
        return match ? match[1] : null;
    }

    /**
     * Wait for configuration form to be fully loaded
     */
    async waitForFormReady(): Promise<void> {
        await expect(this.configForm).toBeVisible();
        await expect(this.submitButton).toBeVisible();
    }

    /**
     * Check if a setting value has changed (useful for validation tests)
     * @param name - The name attribute of the setting
     * @param originalValue - The original value
     */
    async hasSettingChanged(name: string, originalValue: string | boolean): Promise<boolean> {
        const currentValue = await this.getSetting(name);
        return currentValue !== originalValue;
    }

    /**
     * Get a text input's current value
     * @param name - The name attribute of the input
     */
    async getTextValue(name: string): Promise<string> {
        const input = this.getTextInput(name);
        return await input.inputValue();
    }

    /**
     * Fill a text input field
     * @param name - The name attribute of the input
     * @param value - The value to fill
     */
    async fillTextInput(name: string, value: string): Promise<void> {
        const input = this.getTextInput(name);
        await expect(input).toBeVisible();
        await input.fill(value);
    }

    /**
     * Get a checkbox's checked state
     * @param name - The name attribute of the checkbox
     */
    async isCheckboxChecked(name: string): Promise<boolean> {
        const checkbox = this.getCheckbox(name);
        return await checkbox.isChecked();
    }

    /**
     * Get a select field's current value
     * @param name - The name attribute of the select
     */
    async getSelectValue(name: string): Promise<string> {
        const select = this.getSelect(name);
        return await select.inputValue();
    }

    /**
     * Set a select field's value
     * @param name - The name attribute of the select
     * @param value - The value to select
     */
    async selectValue(name: string, value: string): Promise<void> {
        const select = this.getSelect(name);
        await expect(select).toBeVisible();
        await select.selectOption(value);
    }

    /**
     * Wait for and verify a success message
     * @param messageText - The success message text to look for
     */
    async verifySuccess(
        messageText: string = 'Your configuration settings are saved'
    ): Promise<void> {
        const successMsg = this.page.locator(`text=${messageText}`);
        await expect(successMsg).toBeVisible({ timeout: 5000 });
    }

    /**
     * Wait for and verify an error message
     * @param messageText - Optional specific error text to look for
     */
    async verifyError(messageText?: string): Promise<void> {
        await expect(this.errorMessage).toBeVisible({ timeout: 5000 });
        if (messageText) {
            await expect(this.errorMessage).toContainText(messageText);
        }
    }

    /**
     * Look for a button or link to reset/restore settings
     */
    async getResetButton(): Promise<Locator | null> {
        const resetButton = this.page.locator(
            'a[href*="action=restore_settings"], button:has-text("Restore"), a:has-text("Restore")'
        );
        const count = await resetButton.count();
        return count > 0 ? resetButton : null;
    }

    /**
     * Click the reset/restore settings button if available
     */
    async clickResetButton(): Promise<void> {
        const resetButton = await this.getResetButton();
        if (resetButton) {
            await resetButton.click();
            await this.waitForPageLoad();
        } else {
            throw new Error('Reset/Restore button not found');
        }
    }

    /**
     * Get a button or link to restore defaults
     */
    async getRestoreButton(): Promise<Locator | null> {
        const restoreButton = this.page.locator(
            'a[href*="action=restore"], button:has-text("Restore"), a:has-text("Restore")'
        );
        const count = await restoreButton.count();
        return count > 0 ? restoreButton : null;
    }

    /**
     * Click the restore button if available
     */
    async clickRestoreButton(): Promise<void> {
        const restoreButton = await this.getRestoreButton();
        if (restoreButton) {
            await restoreButton.click();
            await this.waitForPageLoad();
        } else {
            throw new Error('Restore button not found');
        }
    }

    /**
     * Get an input field by name with flexible type matching
     * @param name - The name attribute to search for
     */
    getInputField(name: string): Locator {
        return this.page.locator(`input[name="${name}"]`).first();
    }

    /**
     * Get all input fields with a specific name pattern (for array-like inputs like w[file])
     * @param namePattern - The name pattern (supports brackets like w[file])
     */
    getInputFieldByPattern(namePattern: string): Locator {
        return this.page.locator(`input[name="${namePattern}"]`).first();
    }

    /**
     * Get a file input field
     * @param name - The name attribute of the file input
     */
    getFileInput(name: string): Locator {
        return this.page.locator(`input[type="file"][name="${name}"]`).first();
    }

    /**
     * Upload a file to a file input
     * @param name - The name attribute of the file input
     * @param filePath - The path to the file to upload
     */
    async uploadFile(name: string, filePath: string): Promise<void> {
        const fileInput = this.getFileInput(name);
        await expect(fileInput).toBeVisible();
        await fileInput.setInputFiles(filePath);
    }

    /**
     * Get number of form fields matching a name
     * @param name - The name attribute to count
     */
    async countFields(name: string): Promise<number> {
        const fields = this.page.locator(
            `input[name="${name}"], textarea[name="${name}"], select[name="${name}"]`
        );
        return await fields.count();
    }

    /**
     * Reload the page and verify the form is still present
     */
    async reloadAndVerify(): Promise<void> {
        await this.page.reload({ waitUntil: 'domcontentloaded' });
        await this.waitForFormReady();
    }
}
