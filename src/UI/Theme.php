<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\GtkSource\Raw\StyleSchemeManager;

/**
 * Thème visuel inspiré de Visual Studio (dark) : chrome sombre, accent bleu,
 * typographie compacte, schéma d'éditeur dédié.
 */
final class Theme
{
    public const SCHEME_ID = 'vs-dark';

    public static function apply(AdwWindow|Window $window, ?CodeEditor $editor = null): void
    {
        $window->addCss(self::CSS);

        if ($editor === null) {
            return;
        }

        // Repli si le schéma personnalisé n'est pas chargeable.
        $editor->scheme('Adwaita-dark');
        $editor->scheme(self::installScheme());
    }

    private static function installScheme(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/') . '/cyonima-styles';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return 'Adwaita-dark';
        }

        $path = $directory . '/' . self::SCHEME_ID . '.xml';
        if (!is_file($path) || file_get_contents($path) !== self::SCHEME) {
            file_put_contents($path, self::SCHEME);
        }

        $manager = StyleSchemeManager::getDefault();
        if ($manager === null) {
            return 'Adwaita-dark';
        }

        $manager->appendSearchPath($directory);
        $manager->forceRescan();

        return $manager->getScheme(self::SCHEME_ID) !== null
            ? self::SCHEME_ID
            : 'Adwaita-dark';
    }

    private const CSS = <<<'CSS'
window {
    background-color: #1e1e1e;
    color: #cccccc;
    font-family: "Cantarell", "Segoe UI", "Noto Sans", sans-serif;
    font-size: 13px;
}

headerbar {
    background-color: #2d2d30;
    background-image: none;
    box-shadow: none;
    border-bottom: 1px solid #3f3f46;
    min-height: 34px;
    padding: 0 4px;
}

headerbar button {
    background: transparent;
    background-image: none;
    box-shadow: none;
    border: none;
    border-radius: 2px;
    padding: 4px 8px;
    min-height: 24px;
    min-width: 24px;
    color: #cccccc;
}

headerbar button:hover {
    background-color: #3e3e42;
}

headerbar label {
    color: #cccccc;
}

headerbar .window-controls button {
    border-radius: 2px;
    min-width: 24px;
    min-height: 24px;
    padding: 2px;
}

.menu-item {
    padding: 4px 8px;
    color: #cccccc;
    font-size: 13px;
}

.menu-item:hover {
    background-color: #3e3e42;
    border-radius: 2px;
}

.menu-bar {
    background: transparent;
}

.menu-trigger {
    background: transparent;
    background-image: none;
    border: none;
    box-shadow: none;
    border-radius: 2px;
    padding: 4px 9px;
    color: #cccccc;
    min-height: 24px;
}

.menu-trigger:hover {
    background-color: #3e3e42;
}

.menu-popover {
    background-color: #252526;
    border: 1px solid #454545;
    border-radius: 4px;
    padding: 4px;
    min-width: 240px;
}

.menu-action {
    background: transparent;
    background-image: none;
    border: none;
    box-shadow: none;
    border-radius: 2px;
    padding: 7px 10px;
}

.menu-action:hover {
    background-color: #094771;
}

.menu-entry label {
    color: #cccccc;
    font-size: 12px;
}

.menu-shortcut {
    color: #8a8a8a;
    font-size: 10px;
}

.menu-separator {
    background-color: #3f3f46;
    min-height: 1px;
    margin: 4px 6px;
}

.activity-bar {
    background-color: #333333;
    border-right: 1px solid #3f3f46;
}

.activity-bar button {
    background: transparent;
    background-image: none;
    border: none;
    border-radius: 0;
    box-shadow: none;
    padding: 12px;
    color: #9d9d9d;
    -gtk-icon-size: 20px;
}

.activity-bar button:hover {
    background-color: #3e3e42;
    color: #ffffff;
}

.activity-bar button.active {
    color: #ffffff;
    border-left: 2px solid #007acc;
}

.side-panel {
    background-color: #252526;
    border-right: 1px solid #3f3f46;
}

.panel-header-bar {
    background-color: #252526;
    border-bottom: 1px solid #3f3f46;
    padding: 2px 8px 2px 12px;
    min-height: 30px;
}

.panel-header {
    color: #bbbbbb;
    font-size: 11px;
    font-weight: 700;
}

.panel-header-bar button {
    background: transparent;
    border: none;
    box-shadow: none;
    padding: 3px;
    color: #cccccc;
    -gtk-icon-size: 14px;
}

.panel-header-bar button:hover {
    background-color: #3e3e42;
}

list {
    background-color: transparent;
}

list row {
    background-color: transparent;
    border-radius: 0;
    padding: 1px 4px;
}

list row:hover {
    background-color: #2a2d2e;
}

list row:selected {
    background-color: #094771;
    color: #ffffff;
}

.file-row {
    color: #cccccc;
    font-size: 13px;
}

.browser-path {
    color: #969696;
    font-size: 11px;
    padding: 4px 8px;
}

.search-toolbar {
    padding: 4px;
}

.find-bar {
    background-color: #252526;
    border-bottom: 1px solid #3f3f46;
    padding: 4px 6px;
}

.find-status {
    color: #8a8a8a;
    font-size: 11px;
    padding: 0 6px;
}

.find-history-row {
    color: #cccccc;
    font-size: 12px;
    padding: 4px 8px;
}

.find-history-row:hover {
    background-color: #2a2d2e;
}

.find-action {
    font-size: 11px;
    padding: 3px 8px;
    min-height: 0;
}

.markdown-preview {
    background-color: #1e1e1e;
    border-left: 1px solid #3f3f46;
}

.markdown-body {
    color: #cccccc;
    font-size: 14px;
    padding: 12px 16px;
}

.markdown-toc-toggle {
    background: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
    color: #75beff;
    font-size: 11px;
    padding: 4px 8px;
    min-height: 0;
}

.markdown-toc-toggle:hover {
    background-color: #2a2d2e;
}

.markdown-toc-row {
    color: #75beff;
    font-size: 12px;
    padding: 3px 8px;
}

.markdown-toc-row:hover {
    background-color: #2a2d2e;
}

.markdown-toc-toggle-icon {
    -gtk-icon-size: 12px;
    padding: 0;
    min-height: 0;
    min-width: 0;
    background: transparent;
    border: none;
    box-shadow: none;
    color: #8a8a8a;
}

.markdown-toc-toggle-icon:hover {
    color: #ffffff;
}

.search-options {
    padding: 0 4px;
}

.search-toggle {
    padding: 1px 8px;
    min-height: 22px;
    font-size: 12px;
    background-color: transparent;
    border: 1px solid transparent;
    color: #969696;
}

.search-toggle:hover {
    background-color: #2a2d2e;
    color: #cccccc;
}

.search-toggle.active {
    background-color: #094771;
    border-color: #007acc;
    color: #ffffff;
}

.search-count {
    color: #8a8a8a;
    font-size: 11px;
    padding: 2px 6px;
}

.search-result {
    color: #cccccc;
    font-size: 12px;
    padding: 3px 6px;
}

.search-result:hover {
    background-color: #2a2d2e;
}

.problems-summary {
    color: #8a8a8a;
    font-size: 11px;
    padding: 4px 6px;
}

.problem-row {
    color: #cccccc;
    font-size: 12px;
    padding: 3px 6px;
}

.problem-row:hover {
    background-color: #2a2d2e;
}

.problem-error {
    color: #f48771;
}

.problem-warning {
    color: #cca700;
}

.problem-info {
    color: #75beff;
}

.problem-hint {
    color: #969696;
}

.output-toolbar {
    padding: 2px 6px;
}

.output-clear {
    background: transparent;
    border: none;
    box-shadow: none;
    color: #8a8a8a;
    font-size: 11px;
    padding: 2px 6px;
    min-height: 0;
}

.output-clear:hover {
    background-color: #2a2d2e;
    color: #ffffff;
}

.output-text {
    color: #cccccc;
    font-family: monospace;
    font-size: 12px;
    padding: 4px 8px;
}

.git-summary {
    color: #8a8a8a;
    font-size: 11px;
    padding: 4px 6px;
}

.git-branch {
    color: #cccccc;
    font-size: 12px;
    padding: 3px 8px;
    background-color: #2d2d30;
}

.git-branch-row {
    color: #cccccc;
    font-size: 12px;
    padding: 4px 8px;
}

.git-branch-row:hover {
    background-color: #2a2d2e;
}

.git-toolbar {
    padding: 0 4px;
}

.git-action {
    font-size: 11px;
    padding: 3px 8px;
    min-height: 0;
}

.git-row {
    color: #cccccc;
    font-family: monospace;
    font-size: 12px;
    padding: 3px 6px;
}

.git-row:hover {
    background-color: #2a2d2e;
}

.git-untracked {
    color: #73c991;
}

.git-staged {
    color: #75beff;
}

.git-modified {
    color: #cca700;
}

.git-conflict {
    color: #f48771;
    font-weight: 600;
}

.git-row-action {
    -gtk-icon-size: 13px;
    padding: 1px;
    min-height: 0;
    min-width: 0;
    background: transparent;
    border: none;
    box-shadow: none;
    color: #8a8a8a;
}

.git-row-action:hover {
    background-color: #3e3e42;
    color: #ffffff;
}

.diff-title {
    color: #8a8a8a;
    font-size: 11px;
    padding: 4px 8px;
}

.quick-open {
    background-color: #252526;
    border: 1px solid #3f3f46;
}

.quick-open contents {
    padding: 8px;
}

.quick-open-result {
    color: #cccccc;
    font-size: 13px;
    padding: 4px 6px;
}

.quick-open-result:hover {
    background-color: #2a2d2e;
}

.tab-bar {
    background-color: #252526;
    border-bottom: 1px solid #3f3f46;
    min-height: 34px;
}

.tab {
    background-color: #2d2d30;
    color: #969696;
    padding: 0 14px;
    min-height: 34px;
    border-right: 1px solid #252526;
}

.tab.active {
    background-color: #1e1e1e;
    color: #ffffff;
    border-top: 1px solid #007acc;
}

.tab-label {
    font-size: 13px;
}

.tab-dot {
    color: #e7e7e7;
    font-size: 14px;
}

.tab-close {
    -gtk-icon-size: 12px;
    padding: 2px;
    color: #969696;
}

.tab-close:hover {
    background-color: #3e3e42;
    color: #ffffff;
}

.panel-tabs {
    background-color: #1e1e1e;
    border-top: 1px solid #3f3f46;
    padding: 0 10px;
    min-height: 30px;
}

.panel-tab {
    color: #8a8a8a;
    font-size: 11px;
    font-weight: 600;
    padding: 6px 10px;
}

button.panel-tab {
    background: transparent;
    border: none;
    box-shadow: none;
    border-radius: 0;
    min-height: 0;
}

button.panel-tab:hover {
    background-color: #2a2d2e;
    color: #cccccc;
}

.panel-tab.active {
    color: #ffffff;
    border-bottom: 1px solid #007acc;
}

.statusbar {
    background-color: #007acc;
    color: #ffffff;
    font-size: 11px;
    padding: 2px 8px;
    min-height: 22px;
}

.status-item {
    color: #ffffff;
    font-size: 11px;
    padding: 0 8px;
}

textview {
    background-color: #1e1e1e;
    color: #d4d4d4;
    font-family: "DejaVu Sans Mono", "Consolas", "Noto Sans Mono", monospace;
    font-size: 13px;
}

.debug-title {
    color: #8a8a8a;
    font-size: 11px;
    font-weight: 700;
    padding: 8px 8px 2px;
}

scrollbar slider {
    background-color: #4b4b4b;
    border-radius: 4px;
    min-width: 8px;
    min-height: 8px;
}

scrollbar slider:hover {
    background-color: #6b6b6b;
}
/* --- Fenêtre de démarrage (façon Visual Studio) ------------------------- */

.start-sidebar {
    background-color: #252526;
    border-right: 1px solid #3f3f46;
    padding: 16px 10px;
}

.start-brand {
    color: #ffffff;
    font-size: 17px;
    font-weight: 700;
    padding: 2px 8px;
}

.start-brand-sub {
    color: #8a8a8a;
    font-size: 10px;
    padding: 0 8px;
}

.start-section {
    color: #8a8a8a;
    font-size: 10px;
    font-weight: 700;
    padding: 10px 8px 4px;
}

.start-nav-button {
    background: transparent;
    background-image: none;
    border: none;
    box-shadow: none;
    border-radius: 3px;
    padding: 7px 10px;
    color: #cccccc;
}

.start-nav-button:hover {
    background-color: #2a2d2e;
    color: #ffffff;
}

.start-nav-button.active {
    background-color: #094771;
    color: #ffffff;
}

.start-page {
    background-color: #1e1e1e;
}

.start-content {
    padding: 24px 40px 28px;
}

.start-title {
    color: #ffffff;
    font-size: 24px;
    font-weight: 600;
}

.start-subtitle {
    color: #9d9d9d;
    font-size: 13px;
}

.start-card {
    background-color: #252526;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 4px;
    box-shadow: none;
    padding: 10px 12px;
    min-height: 84px;
    color: #cccccc;
}

.start-card:hover {
    background-color: #2d2d30;
    border-color: #007acc;
}

.start-card.selected {
    background-color: #094771;
    border-color: #007acc;
}

.start-card-title {
    color: #ffffff;
    font-size: 14px;
    font-weight: 600;
}

.start-card-desc {
    color: #9d9d9d;
    font-size: 11px;
}

.start-card.selected .start-card-desc {
    color: #d7e8f7;
}

.start-field-label {
    color: #cccccc;
    font-size: 12px;
    font-weight: 600;
}

.start-entry {
    background-color: #3c3c3c;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    padding: 6px 8px;
    min-height: 22px;
    color: #ffffff;
    box-shadow: none;
}

.start-entry:focus {
    border-color: #007acc;
}

.start-primary {
    background-color: #0e639c;
    background-image: none;
    border: none;
    border-radius: 2px;
    box-shadow: none;
    padding: 7px 18px;
    color: #ffffff;
    font-weight: 600;
}

.start-primary:hover {
    background-color: #1177bb;
}

.start-choice {
    background-color: #3a3d41;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    box-shadow: none;
    padding: 5px 12px;
    color: #cccccc;
    font-size: 11px;
}

.start-choice.selected {
    background-color: #094771;
    border-color: #007acc;
    color: #ffffff;
}

.start-hint {
    color: #8a8a8a;
    font-size: 11px;
}

.start-error {
    color: #f48771;
    font-size: 12px;
}

.start-success {
    color: #4ec9b0;
    font-size: 12px;
}

.start-recent-item {
    background: transparent;
    background-image: none;
    border: none;
    box-shadow: none;
    border-radius: 3px;
    padding: 6px 8px;
    color: #cccccc;
}

.start-recent-item:hover {
    background-color: #2a2d2e;
}

.start-recent-name {
    color: #dddddd;
    font-size: 12px;
}

.start-recent-path {
    color: #8a8a8a;
    font-size: 10px;
}

.start-recent-missing {
    color: #f48771;
}

.start-asset {
    background-color: #252526;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    padding: 5px 10px;
    color: #cccccc;
    font-size: 11px;
}

.start-footer {
    color: #8a8a8a;
    font-size: 10px;
    padding: 8px;
}

/* --- Panneau Assets ------------------------------------------------------ */

.assets-panel {
    padding: 6px 8px;
}

.asset-entry {
    background-color: #3c3c3c;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    padding: 4px 6px;
    min-height: 20px;
    color: #ffffff;
    box-shadow: none;
    font-size: 11px;
}

.asset-add {
    background-color: #0e639c;
    background-image: none;
    border: none;
    border-radius: 2px;
    box-shadow: none;
    padding: 4px 10px;
    color: #ffffff;
    font-size: 11px;
}

.asset-add:hover {
    background-color: #1177bb;
}

.asset-row {
    background-color: transparent;
    background-image: none;
    border: none;
    box-shadow: none;
    border-radius: 2px;
    padding: 4px 6px;
}

.asset-row:hover {
    background-color: #2a2d2e;
}

.asset-name {
    color: #dddddd;
    font-size: 12px;
}

.asset-meta {
    color: #8a8a8a;
    font-size: 10px;
}

.asset-empty {
    color: #8a8a8a;
    font-size: 11px;
    padding: 6px;
}

.asset-message {
    color: #4ec9b0;
    font-size: 10px;
    padding: 2px 4px;
}

.asset-message.error {
    color: #f48771;
}

/* --- Propriétés du projet ------------------------------------------------ */

.project-panel {
    padding: 4px 0 10px;
}

.project-section {
    color: #8a8a8a;
    font-size: 10px;
    font-weight: 700;
    padding: 12px 10px 4px;
}

.project-row {
    padding: 5px 10px;
    border-bottom: 1px solid #2a2d2e;
}

.project-key {
    color: #8a8a8a;
    font-size: 10px;
}

.project-value {
    color: #dddddd;
    font-size: 12px;
}

.project-choice {
    background-color: #3a3d41;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    box-shadow: none;
    padding: 6px 10px;
    color: #cccccc;
    font-size: 11px;
    margin: 0 10px;
}

.project-choice:hover {
    background-color: #45494e;
}

.project-choice.selected {
    background-color: #094771;
    border-color: #007acc;
    color: #ffffff;
}

.project-apply {
    background-color: #0e639c;
    background-image: none;
    border: none;
    border-radius: 2px;
    box-shadow: none;
    padding: 6px 12px;
    color: #ffffff;
    font-size: 11px;
    margin: 8px 10px 0;
}

.project-apply:hover {
    background-color: #1177bb;
}

.project-message {
    color: #4ec9b0;
    font-size: 10px;
    padding: 4px 10px;
}

.project-message.error {
    color: #f48771;
}

.project-note {
    color: #8a8a8a;
    font-size: 10px;
    padding: 2px 10px;
}

/* --- Concepteur d'interface --------------------------------------------- */

.designer-palette {
    background-color: #252526;
    border-right: 1px solid #3f3f46;
    padding: 10px;
}

.designer-properties {
    background-color: #252526;
    border-left: 1px solid #3f3f46;
    padding: 10px;
}

.designer-section {
    color: #8a8a8a;
    font-size: 10px;
    font-weight: 700;
    padding: 10px 2px 4px;
}

.designer-hint {
    color: #8a8a8a;
    font-size: 10px;
    padding: 2px;
}

.designer-palette-item {
    background-color: #2d2d30;
    border: 1px solid #3f3f46;
    border-radius: 3px;
    padding: 8px 10px;
    margin: 3px 0;
}

.designer-palette-item:hover {
    background-color: #333337;
    border-color: #007acc;
}

.designer-form-item {
    background-color: transparent;
    background-image: none;
    border: 1px solid transparent;
    border-radius: 2px;
    box-shadow: none;
    padding: 5px 8px;
    color: #cccccc;
    font-size: 11px;
}

.designer-form-item:hover {
    background-color: #2a2d2e;
    border-color: #3f3f46;
}

.designer-form-item.selected {
    background-color: #094771;
    border-color: #007acc;
    color: #ffffff;
}

.designer-form-new {
    background-color: #2d2d30;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    box-shadow: none;
    padding: 5px 8px;
    color: #cccccc;
    font-size: 10px;
    margin-top: 4px;
}

.designer-form-new:hover {
    background-color: #333337;
    border-color: #007acc;
}

.designer-palette-name {
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
}

.designer-palette-hint {
    color: #8a8a8a;
    font-size: 10px;
}

.designer-canvas-header {
    background-color: #252526;
    border-bottom: 1px solid #3f3f46;
    padding: 2px 10px;
    min-height: 30px;
}

.designer-canvas-wrap {
    background-color: #1e1e1e;
}

.designer-canvas {
    background-color: #2b2b2e;
    border: 1px solid #4a4a50;
    border-radius: 3px;
}

.designer-item {
    border: 1px solid transparent;
    border-radius: 2px;
    padding: 0;
}

.designer-item:hover {
    border-color: #5a5a60;
}

.designer-item.selected {
    border: 1px solid #007acc;
}

.asset-picker {
    background-color: #252526;
    border: 1px solid #454545;
    border-radius: 4px;
    padding: 8px;
    min-width: 280px;
}

.asset-picker-title {
    color: #8a8a8a;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 4px 4px;
}

.asset-picker-row {
    background-color: transparent;
    background-image: none;
    border: none;
    border-radius: 2px;
    box-shadow: none;
    padding: 5px 7px;
}

.asset-picker-row:hover {
    background-color: #094771;
}

.asset-picker-name {
    color: #dddddd;
    font-size: 11px;
}

.asset-picker-kind {
    color: #8a8a8a;
    font-size: 10px;
}

.asset-picker-browse {
    background-color: #0e639c;
    background-image: none;
    border: none;
    border-radius: 2px;
    box-shadow: none;
    padding: 6px 10px;
    color: #ffffff;
    font-size: 10px;
    margin-top: 4px;
}

.asset-picker-browse:hover {
    background-color: #1177bb;
}

.asset-picker-empty {
    color: #8a8a8a;
    font-size: 10px;
    padding: 6px;
}

.designer-browse {
    background-color: #3a3d41;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    box-shadow: none;
    padding: 4px;
    min-width: 24px;
    min-height: 24px;
    color: #dddddd;
    -gtk-icon-size: 14px;
}

.designer-browse:hover {
    background-color: #45494e;
    border-color: #007acc;
}

.designer-entry {
    background-color: #3c3c3c;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    padding: 5px 7px;
    min-height: 20px;
    color: #ffffff;
    box-shadow: none;
    font-size: 11px;
}

.designer-field {
    padding: 3px 0;
}

.designer-field-label {
    color: #bbbbbb;
    font-size: 10px;
    font-weight: 600;
}

.designer-selection {
    color: #ffffff;
    font-size: 14px;
    font-weight: 600;
}

.designer-event {
    background-color: #3a3d41;
    background-image: none;
    border: 1px solid #3f3f46;
    border-radius: 2px;
    box-shadow: none;
    padding: 5px 9px;
    color: #cccccc;
    font-size: 10px;
    margin: 1px 0;
}

.designer-event:hover {
    background-color: #45494e;
}

.designer-event.selected {
    background-color: #094771;
    border-color: #007acc;
    color: #ffffff;
}

.designer-type {
    color: #8a8a8a;
    font-size: 11px;
    padding-bottom: 4px;
}

.designer-save {
    background-color: #0e639c;
    background-image: none;
    border: none;
    border-radius: 2px;
    box-shadow: none;
    padding: 7px 12px;
    color: #ffffff;
    font-weight: 600;
    margin-top: 8px;
}

.designer-save:hover {
    background-color: #1177bb;
}

.designer-apply {
    background-color: #0e639c;
    background-image: none;
    border: none;
    border-radius: 2px;
    box-shadow: none;
    padding: 6px 12px;
    color: #ffffff;
    font-size: 11px;
    margin-top: 8px;
}

.designer-apply:hover {
    background-color: #1177bb;
}

.designer-delete {
    background-color: #3a3d41;
    background-image: none;
    border: none;
    border-radius: 2px;
    box-shadow: none;
    padding: 6px 12px;
    color: #dddddd;
    font-size: 11px;
    margin-top: 8px;
}

.designer-delete:hover {
    background-color: #5a2d2d;
    color: #ffffff;
}

.designer-message {
    color: #4ec9b0;
    font-size: 10px;
    padding: 5px 2px;
}

.designer-message.error {
    color: #f48771;
}

CSS;

    private const SCHEME = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<style-scheme id="vs-dark" name="Visual Studio Dark" version="1.0">
  <author>Cyonima</author>
  <description>Palette proche de Visual Studio Dark</description>
  <color name="bg" value="#1e1e1e"/>
  <color name="fg" value="#d4d4d4"/>
  <color name="sel" value="#264f78"/>
  <color name="line" value="#2a2d2e"/>
  <color name="caret" value="#aeafad"/>
  <color name="keyword" value="#569cd6"/>
  <color name="control" value="#c586c0"/>
  <color name="string" value="#ce9178"/>
  <color name="comment" value="#6a9955"/>
  <color name="number" value="#b5cea8"/>
  <color name="type" value="#4ec9b0"/>
  <color name="func" value="#dcdcaa"/>
  <color name="var" value="#9cdcfe"/>
  <color name="const" value="#4fc1ff"/>
  <color name="preproc" value="#9b9b9b"/>
  <style name="text" foreground="fg" background="bg"/>
  <style name="selection" background="sel"/>
  <style name="cursor" foreground="caret"/>
  <style name="current-line" background="line"/>
  <style name="line-numbers" foreground="#858585" background="bg"/>
  <style name="bracket-match" background="#3a3d41"/>
  <style name="search-match" background="#613214"/>
  <style name="def:keyword" foreground="keyword"/>
  <style name="def:builtin" foreground="keyword"/>
  <style name="def:constant" foreground="const"/>
  <style name="def:string" foreground="string"/>
  <style name="def:comment" foreground="comment" italic="true"/>
  <style name="def:number" foreground="number"/>
  <style name="def:type" foreground="type"/>
  <style name="def:function" foreground="func"/>
  <style name="def:identifier" foreground="var"/>
  <style name="def:variable" foreground="var"/>
  <style name="def:preprocessor" foreground="preproc"/>
  <style name="def:operator" foreground="fg"/>
  <style name="def:special-char" foreground="number"/>
  <style name="def:control" foreground="control"/>
</style-scheme>
XML;
}
