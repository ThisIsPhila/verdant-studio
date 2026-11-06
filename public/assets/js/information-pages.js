(() => {
  const header = document.querySelector(".site-header");
  if (header) {
    const updateHeaderOffset = () => document.documentElement.style.setProperty("--site-header-height", `${header.getBoundingClientRect().height}px`);
    updateHeaderOffset();
    new ResizeObserver(updateHeaderOffset).observe(header);
  }
  document.querySelectorAll(".info-contents-mobile a").forEach((link) => {
    link.addEventListener("click", () => { link.closest("details").open = false; });
  });
  document.querySelectorAll(".info-contents").forEach((contents) => {
    const links = [...contents.querySelectorAll('a[href^="#"]')];
    const sections = [...new Set(links.map((link) => document.getElementById(link.hash.slice(1))).filter(Boolean))];
    const updateCurrent = () => {
      const threshold = (header?.getBoundingClientRect().height ?? 80) + 100;
      const current = sections.filter((section) => section.getBoundingClientRect().top <= threshold).at(-1) ?? sections[0];
      links.forEach((link) => {
        if (link.hash === `#${current?.id}`) link.setAttribute("aria-current", "location");
        else link.removeAttribute("aria-current");
      });
    };
    window.addEventListener("scroll", updateCurrent, { passive: true });
    updateCurrent();
  });
  const normalize = (value) => value.trim().toLocaleLowerCase();

  document.querySelectorAll(".info-page[data-info-interactions]").forEach((page) => {
    const tabs = [...page.querySelectorAll("[data-info-filter]")];
    const searchable = [...page.querySelectorAll("[data-info-category]")];
    const search = page.querySelector("[data-info-search]");
    let active = tabs.find((tab) => tab.getAttribute("aria-pressed") === "true")?.dataset.infoFilter ?? "all";

    if (page.classList.contains("info-page-plant-care")) {
      const previews = [...page.querySelectorAll("[data-care-preview]")];
      tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
          active = tab.dataset.infoFilter ?? "";
          tabs.forEach((item) => item.setAttribute("aria-pressed", String(item === tab)));
          previews.forEach((preview) => {
            preview.hidden = preview.dataset.carePreview !== active;
          });
        });
      });
      previews.forEach((preview) => {
        preview.hidden = preview.dataset.carePreview !== active;
      });
      return;
    }

    const update = () => {
      const query = normalize(search?.value ?? "");
      let visible = 0;
      searchable.forEach((item) => {
        const matchesCategory = active === "all" || item.dataset.infoCategory === active;
        const matchesQuery = !query || normalize(item.textContent ?? "").includes(query);
        item.hidden = !matchesCategory || !matchesQuery;
        if (!item.hidden) visible += 1;
      });
      const empty = page.querySelector("[data-info-empty]");
      if (empty) empty.hidden = visible !== 0;
    };

    tabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        active = tab.dataset.infoFilter ?? "all";
        tabs.forEach((item) => item.setAttribute("aria-pressed", String(item === tab)));
        update();
      });
    });
    tabs.forEach((tab) => tab.setAttribute("aria-pressed", String((tab.dataset.infoFilter ?? "all") === active)));
    page.querySelector("[data-info-reset]")?.addEventListener("click", () => {
      active = "all";
      if (search) search.value = "";
      tabs.forEach((tab) => tab.setAttribute("aria-pressed", String(tab.dataset.infoFilter === "all")));
      update();
    });
    search?.addEventListener("input", update);
    update();
  });

  document.querySelectorAll(".info-page [data-contact-toggle]").forEach((button) => {
    button.addEventListener("click", () => {
      const note = document.getElementById(button.getAttribute("aria-controls") ?? "");
      const expanded = button.getAttribute("aria-expanded") === "true";
      button.setAttribute("aria-expanded", String(!expanded));
      if (note) {
        note.hidden = expanded;
        if (!expanded) note.querySelector('input:not([type="hidden"])')?.focus();
      }
    });
  });
})();
