(function () {
  "use strict";

  if (typeof customElements === "undefined" || customElements.get("prefill-storefront-select")) {
    return;
  }

  class PrefillStorefrontSelect extends HTMLElement {
    constructor() {
      super();
      this.selectedCode = "*";
      this.contentCache = {};
      this.loading = false;
      this._requestSeq = 0;
      this._xhr = null;
      this._initialized = false;
      this._els = { select: null, wrapper: null, loader: null };
      this._onHostChange = (event) => {
        const target = event && event.target;
        if (target && target.matches && target.matches('select[data-id="storefront-select"]')) {
          this.onStorefrontChange();
        }
      };
    }

    connectedCallback() {
      this.addEventListener("change", this._onHostChange, true);

      if (!this._initialized) {
        this.render();
        this._initialized = true;
      }

      this.selectedCode = this.getAttribute("selected") || this.getSelectedFromDom() || "*";
      this.updateSelect();

      if (this.getAttribute("module")) {
        const wrapper = this.getContentsWrapper();
        const hasContent = wrapper && wrapper.querySelectorAll("[data-storefront-code]").length > 0;

        if (hasContent) {
          const currentContainer = wrapper.querySelector(
            '[data-storefront-code="' + this.escapeSelector(this.selectedCode) + '"]'
          );
          if (currentContainer) {
            this.dispatchStorefrontShown(this.selectedCode, currentContainer);
          }
        } else {
          this.loadContent(this.selectedCode);
        }
      }
    }

    disconnectedCallback() {
      this.removeEventListener("change", this._onHostChange, true);
      if (this._xhr && typeof this._xhr.abort === "function") {
        try {
          this._xhr.abort();
        } catch (e) {
          // noop
        }
      }
    }

    static get observedAttributes() {
      return [
        "storefronts",
        "module",
        "params",
        "name",
        "default-label",
        "selected",
        "show-status",
        "cache",
        "contents-wrapper-selector",
      ];
    }

    attributeChangedCallback(name, oldValue, newValue) {
      if (oldValue === newValue || !this.isConnected) {
        return;
      }

      if (name === "selected") {
        this.selectedCode = newValue || "*";
        this.updateSelect();
        if (this.getAttribute("module")) {
          this.switchStorefront(this.selectedCode);
        }
        return;
      }

      if (name !== "params") {
        this.render();
      }
    }

    isCacheEnabled() {
      return this.getAttribute("cache") !== "false";
    }

    render() {
      const data = this.parseJSON(this.getAttribute("storefronts"), {});
      const storefronts = Array.isArray(data.storefronts) ? data.storefronts : [];
      const statuses = data.statuses && typeof data.statuses === "object" ? data.statuses : {};
      const defaultLabel = this.getAttribute("default-label") || "General";
      const name = this.getAttribute("name");
      const showStatus = this.getAttribute("show-status") === "true";
      const module = this.getAttribute("module");

      while (this.firstChild) {
        this.removeChild(this.firstChild);
      }

      const root = document.createElement("div");
      root.className = "prefill-storefront-select-wrapper";

      const waSelect = document.createElement("div");
      waSelect.className = "wa-select";
      const select = document.createElement("select");
      select.setAttribute("data-id", "storefront-select");
      if (name) {
        select.setAttribute("name", name);
      }

      select.appendChild(this.createOptionElement("*", defaultLabel, showStatus, statuses["*"]));
      storefronts.forEach((group) => {
        const optgroup = document.createElement("optgroup");
        optgroup.label = group && group.domain ? String(group.domain) : "";
        const items = group && Array.isArray(group.items) ? group.items : [];
        items.forEach((sf) => {
          if (!sf) {
            return;
          }
          optgroup.appendChild(this.createOptionElement(sf.code, sf.url, showStatus, statuses[sf.code]));
        });
        select.appendChild(optgroup);
      });

      waSelect.appendChild(select);
      root.appendChild(waSelect);

      let wrapper = null;
      let loader = null;
      if (module) {
        const externalSelector = this.getAttribute("contents-wrapper-selector");
        if (externalSelector) {
          wrapper = document.querySelector(externalSelector);
          if (wrapper) {
            loader = wrapper.querySelector('[data-id="storefront-loading"]');
            if (!loader) {
              loader = this.createLoadingElement();
              wrapper.appendChild(loader);
            }
          }
        } else {
          wrapper = document.createElement("div");
          wrapper.className = "prefill-storefront-contents-wrapper";
          wrapper.setAttribute("data-id", "storefront-contents-wrapper");
          loader = this.createLoadingElement();
          wrapper.appendChild(loader);
          root.appendChild(wrapper);
        }
      }

      this.appendChild(root);
      this._els.select = select;
      this._els.wrapper = this.getAttribute("contents-wrapper-selector") ? null : wrapper;
      this._els.loader = this.getAttribute("contents-wrapper-selector") ? null : loader;

      this.restoreCachedContainers();
      this.updateSelect();
    }

    createOptionElement(value, label, showStatus, status) {
      const option = document.createElement("option");
      const v = value != null ? String(value) : "";
      const l = label != null ? String(label) : "";
      option.value = v;
      option.dataset.code = v;
      option.dataset.label = l;
      const statusIcon = showStatus ? (status ? "🟢 " : "⚪ ") : "";
      option.textContent = statusIcon + l;
      return option;
    }

    updateOptionStatus(code, isActive) {
      const select = this._els.select || this.querySelector("select");
      if (!select || this.getAttribute("show-status") !== "true") {
        return;
      }
      const option = select.querySelector('option[data-code="' + this.escapeSelector(code) + '"]');
      if (!option) {
        return;
      }
      option.textContent = (isActive ? "🟢 " : "⚪ ") + (option.dataset.label || "");
    }

    createLoadingElement() {
      const loadingEl = document.createElement("div");
      loadingEl.className = "prefill-loading";
      loadingEl.setAttribute("data-id", "storefront-loading");
      loadingEl.style.display = "none";
      loadingEl.style.minHeight = "150px";
      loadingEl.style.padding = "10px 0";
      const icon = document.createElement("i");
      icon.className = "icon16 loading";
      loadingEl.appendChild(icon);
      return loadingEl;
    }

    getContentsWrapper() {
      const externalSelector = this.getAttribute("contents-wrapper-selector");
      if (externalSelector) {
        return document.querySelector(externalSelector);
      }
      return this._els.wrapper || this.querySelector('[data-id="storefront-contents-wrapper"]');
    }

    getLoadingEl(wrapper) {
      const currentWrapper = wrapper || this.getContentsWrapper();
      if (!currentWrapper) {
        return null;
      }
      return this._els.loader || currentWrapper.querySelector('[data-id="storefront-loading"]');
    }

    getSelectedFromDom() {
      const select = this._els.select || this.querySelector('select[data-id="storefront-select"]');
      return select ? select.value : null;
    }

    updateSelect() {
      const select = this._els.select || this.querySelector("select");
      if (select) {
        select.value = this.selectedCode;
      }
    }

    hideAllStorefrontContainers(wrapper) {
      const currentWrapper = wrapper || this.getContentsWrapper();
      if (!currentWrapper) {
        return;
      }
      const containers = currentWrapper.querySelectorAll("[data-storefront-code]");
      containers.forEach((container) => {
        container.style.display = "none";
      });
    }

    onStorefrontChange() {
      const select = this._els.select || this.querySelector("select");
      if (!select) {
        return;
      }
      this.selectedCode = select.value;
      if (this.getAttribute("module")) {
        this.switchStorefront(this.selectedCode);
      }
    }

    switchStorefront(code) {
      if (this.isCacheEnabled() && this.contentCache[code]) {
        this.showStorefrontContainer(code);
        this.dispatchStorefrontShown(code, this.contentCache[code]);
        return;
      }
      this.loadContent(code);
    }

    showLoading() {
      const wrapper = this.getContentsWrapper();
      if (!wrapper) {
        return;
      }
      this.hideAllStorefrontContainers(wrapper);
      const loadingEl = this.getLoadingEl(wrapper);
      if (loadingEl) {
        loadingEl.style.display = "block";
      }
    }

    showStorefrontContainer(code) {
      const wrapper = this.getContentsWrapper();
      if (!wrapper) {
        return;
      }

      this.hideAllStorefrontContainers(wrapper);
      const targetContainer = wrapper.querySelector('[data-storefront-code="' + this.escapeSelector(code) + '"]');
      if (targetContainer) {
        targetContainer.style.display = "block";
      }

      const loadingEl = this.getLoadingEl(wrapper);
      if (loadingEl) {
        loadingEl.style.display = "none";
      }
    }

    loadContent(code) {
      const module = this.getAttribute("module");
      if (!module || typeof $ === "undefined") {
        return;
      }

      const storefrontCode = code || this.selectedCode;
      if (this._xhr && typeof this._xhr.abort === "function") {
        try {
          this._xhr.abort();
        } catch (e) {
          // noop
        }
      }

      const requestId = ++this._requestSeq;
      this.loading = true;
      this.showLoading();
      const params = Object.assign({ code: storefrontCode }, this.parseJSON(this.getAttribute("params"), {}));

      this._xhr = $.post("?module=" + module, params)
        .done((html) => {
          if (requestId !== this._requestSeq) {
            return;
          }
          this.loading = false;
          this.createStorefrontContainer(storefrontCode, html);
        })
        .fail((_xhr, status) => {
          if (requestId !== this._requestSeq) {
            return;
          }
          this.loading = false;
          if (status === "abort") {
            return;
          }
          this.showStorefrontContainer(storefrontCode);
          console.error("PrefillStorefrontSelect: Failed to load storefront content", storefrontCode);
        });
    }

    createStorefrontContainer(code, html) {
      const wrapper = this.getContentsWrapper();
      if (!wrapper) {
        return;
      }

      if (!this.isCacheEnabled()) {
        const oldContainers = wrapper.querySelectorAll("[data-storefront-code]");
        oldContainers.forEach((container) => container.remove());
        this.contentCache = {};
      }

      const existing = wrapper.querySelector('[data-storefront-code="' + this.escapeSelector(code) + '"]');
      if (existing) {
        existing.remove();
        delete this.contentCache[code];
      }

      const container = document.createElement("div");
      container.className = "prefill-storefront-content";
      container.setAttribute("data-storefront-code", code);
      container.setAttribute("data-id", "storefront-content");
      container.innerHTML = html;

      const loadingEl = this.getLoadingEl(wrapper);
      wrapper.insertBefore(container, loadingEl);

      if (this.isCacheEnabled()) {
        this.contentCache[code] = container;
      }

      this.showStorefrontContainer(code);
      this.dispatchStorefrontLoaded(code, container);
    }

    restoreCachedContainers() {
      const wrapper = this.getContentsWrapper();
      if (!wrapper) {
        return;
      }
      const loadingEl = this.getLoadingEl(wrapper);
      Object.keys(this.contentCache).forEach((code) => {
        const container = this.contentCache[code];
        wrapper.insertBefore(container, loadingEl);
        container.style.display = code === this.selectedCode ? "block" : "none";
      });
    }

    dispatchStorefrontLoaded(code, container) {
      if (typeof CustomEvent === "undefined") {
        return;
      }
      this.dispatchEvent(
        new CustomEvent("prefill:storefront-content-loaded", {
          bubbles: true,
          detail: { code: code, container: container, component: this },
        })
      );
    }

    dispatchStorefrontShown(code, container) {
      if (typeof CustomEvent === "undefined") {
        return;
      }
      this.dispatchEvent(
        new CustomEvent("prefill:storefront-content-shown", {
          bubbles: true,
          detail: { code: code, container: container, component: this },
        })
      );
    }

    parseJSON(value, defaultValue) {
      if (!value) {
        return defaultValue;
      }
      try {
        return JSON.parse(value);
      } catch (e) {
        try {
          const textarea = document.createElement("textarea");
          textarea.innerHTML = value;
          return JSON.parse(textarea.value);
        } catch (e2) {
          return defaultValue;
        }
      }
    }

    escapeSelector(selector) {
      const s = selector != null ? String(selector) : "";
      if (typeof CSS !== "undefined" && CSS && typeof CSS.escape === "function") {
        return CSS.escape(s);
      }
      return s.replace(/([!"#$%&'()*+,./:;<=>?@[\\\]^`{|}~])/g, "\\$1");
    }
  }

  customElements.define("prefill-storefront-select", PrefillStorefrontSelect);
})();
