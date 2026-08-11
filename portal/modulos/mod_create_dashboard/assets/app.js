(() => {
  "use strict";

  const $ = (id) => document.getElementById(id);
  const config = window.PERFECT_STORE_CONFIG || {};
  const templates = JSON.parse($("template-data")?.textContent || "[]");
  let selectedTemplate = templates[0];
  let states = [];
  let materials = [];
  let information = [];
  let dataFields = [];
  let surveyQuestions = [];
  let plannedLocales = 0;
  let scope = null;
  let elements = [];
  let selectedId = null;
  let nextId = 1;
  let zoom = 0.7;
  let zIndex = 1;
  let pendingQuestion = null;
  let pendingQuestionDetail = null;
  let pendingState = null;
  let pendingDataField = null;
  let copiedElement = null;
  let pasteSequence = 0;

  const canvas = $("dashboard-canvas");
  const templateView = $("template-view");
  const editorView = $("editor-view");
  const modal = $("scope-modal");
  const kpiModal = $("kpi-modal");
  const chartModal = $("chart-modal");
  const dataModal = $("data-modal");
  const divisionSelect = $("division-select");
  const subdivisionSelect = $("subdivision-select");
  const scopeDateFrom = $("scope-date-from");
  const activitySelect = $("activity-select");

  const escapeName = (value) => String(value || "").trim();
  const showToast = (message, type = "success") => {
    const toast = $("toast");
    toast.textContent = message;
    toast.className = `toast show ${type}`;
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => { toast.className = "toast"; }, 3200);
  };

  const apiRequest = async (url, options = {}) => {
    const response = await fetch(url, { credentials: "same-origin", cache: "no-store", ...options });
    const contentType = response.headers.get("Content-Type") || "";
    if (!contentType.includes("application/json")) throw new Error(`Respuesta inesperada del servidor (HTTP ${response.status}).`);
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.message || `Error HTTP ${response.status}`);
    return payload;
  };

  const updateTemplatePreview = (template) => {
    selectedTemplate = template;
    $("preview-title").textContent = `Perfect Store · ${template.name}`;
    $("preview-description").textContent = template.description;
    $("preview-url").textContent = `preview / perfect-store / ${template.id}`;
    $("dashboard-image").src = template.image;
    $("dashboard-image").alt = `Vista previa del dashboard Perfect Store para ${template.name}`;
    $("image-stage").style.setProperty("--accent", template.tone);
    $("preview-watermark").textContent = template.name;
    document.querySelectorAll("[data-template-id]").forEach((button) => {
      const active = button.dataset.templateId === template.id;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-selected", String(active));
    });
  };

  document.querySelectorAll("[data-template-id]").forEach((button) => {
    button.addEventListener("click", () => {
      const template = templates.find((item) => item.id === button.dataset.templateId);
      if (template) updateTemplatePreview(template);
    });
  });

  document.querySelectorAll("[data-fit]").forEach((button) => {
    button.addEventListener("click", () => {
      const contain = button.dataset.fit === "contain";
      $("dashboard-image").classList.toggle("fit-contain", contain);
      $("dashboard-image").classList.toggle("fit-cover", !contain);
      document.querySelectorAll("[data-fit]").forEach((item) => item.classList.toggle("active", item === button));
    });
  });

  const loadCatalogs = async (divisionId = "") => {
    $("scope-feedback").textContent = "Cargando opciones...";
    const suffix = divisionId ? `&id_division=${encodeURIComponent(divisionId)}` : "";
    try {
      const data = await apiRequest(`${config.api}?action=catalogs${suffix}`);
      if (!divisionId) {
        divisionSelect.innerHTML = '<option value="">Selecciona una división</option>';
        data.divisions.forEach((item) => divisionSelect.add(new Option(item.nombre, item.id)));
      }
      subdivisionSelect.innerHTML = '<option value="">Selecciona una subdivisión</option>';
      if (divisionId) subdivisionSelect.add(new Option("Todas (incluye formularios sin subdivisión)", "0"));
      (data.subdivisions || []).forEach((item) => subdivisionSelect.add(new Option(item.nombre, item.id)));
      subdivisionSelect.disabled = !divisionId;
      activitySelect.innerHTML = '<option value="">Selecciona primero una subdivisión</option>';
      activitySelect.disabled = true;
      $("scope-feedback").textContent = data.divisions?.length === 0 ? "No hay divisiones disponibles para tu sesión." : "";
    } catch (error) {
      $("scope-feedback").textContent = error.message;
      divisionSelect.innerHTML = '<option value="">No disponible</option>';
    }
  };

  const loadActivities = async () => {
    activitySelect.disabled = true;
    activitySelect.innerHTML = '<option value="">Cargando actividades...</option>';
    try {
      const query = new URLSearchParams({ action: "activities", id_division: divisionSelect.value, id_subdivision: subdivisionSelect.value });
      const data = await apiRequest(`${config.api}?${query.toString()}`);
      activitySelect.innerHTML = "";
      activitySelect.add(new Option("Todas las actividades / gestiones", "0"));
      (data.activities || []).forEach((item) => activitySelect.add(new Option(item.nombre, item.id)));
      activitySelect.disabled = false;
      activitySelect.value = "0";
    } catch (error) {
      activitySelect.innerHTML = '<option value="">No fue posible cargar actividades</option>';
      $("scope-feedback").textContent = error.message;
    }
  };

  const openScopeModal = () => {
    modal.hidden = false;
    requestAnimationFrame(() => modal.classList.add("show"));
    if (divisionSelect.options.length <= 1 || divisionSelect.value === "") loadCatalogs();
  };
  const closeScopeModal = () => {
    modal.classList.remove("show");
    window.setTimeout(() => { modal.hidden = true; }, 180);
  };

  $("use-template").addEventListener("click", openScopeModal);
  $("cancel-scope").addEventListener("click", closeScopeModal);
  $("change-scope").addEventListener("click", openScopeModal);
  divisionSelect.addEventListener("change", async () => {
    subdivisionSelect.disabled = true;
    $("continue-scope").disabled = true;
    await loadCatalogs(divisionSelect.value);
  });
  const validateScopeForm = () => { $("continue-scope").disabled = !divisionSelect.value || !subdivisionSelect.value || !scopeDateFrom.value || !activitySelect.value; };
  subdivisionSelect.addEventListener("change", async () => {
    $("continue-scope").disabled = true;
    if (subdivisionSelect.value) await loadActivities();
    validateScopeForm();
  });
  scopeDateFrom.addEventListener("change", validateScopeForm);
  activitySelect.addEventListener("change", validateScopeForm);

  const saveDraft = () => {
    if (!scope) return;
    const draft = { version: 1, template: selectedTemplate, scope, canvas: { width: Number($("canvas-width").value), height: Number($("canvas-height").value) }, elements };
    try { localStorage.setItem("perfectStoreDashboardDraft", JSON.stringify(draft)); } catch (_) { /* El editor continúa aunque el navegador bloquee el borrador local. */ }
    $("save-state").innerHTML = "<i></i> Borrador actualizado";
    window.clearTimeout(saveDraft.timer);
    saveDraft.timer = window.setTimeout(() => { $("save-state").innerHTML = "<i></i> Borrador local"; }, 1600);
  };

  const createElement = (data) => {
    const item = {
      id: `element-${nextId++}`,
      type: "text",
      x: 60,
      y: 60,
      width: 320,
      height: 80,
      content: "Nuevo título",
      src: "",
      fontFamily: "Arial, sans-serif",
      fontSize: 28,
      fontWeight: "700",
      color: "#17243b",
      background: "#ffffff",
      align: "left",
      radius: 8,
      objectFit: "contain",
      shapeKind: "rectangle",
      shapeFill: "#4f72d8",
      shapeStroke: "#274b9f",
      shapeStrokeWidth: 3,
      shapeStrokeStyle: "solid",
      rotation: 0,
      chartKind: "bar",
      series: [],
      chartColors: ["#4f72d8", "#579087", "#b07a42", "#7562a7"],
      legendPosition: "right",
      z: ++zIndex,
      ...data,
    };
    elements.push(item);
    renderElements();
    selectElement(item.id);
    saveDraft();
    return item;
  };

  const shapeSvg = (item) => {
    const ns = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(ns, "svg");
    svg.setAttribute("viewBox", "0 0 100 100");
    svg.setAttribute("preserveAspectRatio", "none");
    svg.setAttribute("role", "img");
    svg.setAttribute("aria-label", item.content || "Forma");
    const dash = item.shapeStrokeStyle === "dashed" ? "10 6" : item.shapeStrokeStyle === "dotted" ? "2 6" : "";
    const styleShape = (node, fill = item.shapeFill) => {
      node.setAttribute("fill", fill);
      node.setAttribute("stroke", item.shapeStroke);
      node.setAttribute("stroke-width", String(item.shapeStrokeWidth));
      node.setAttribute("vector-effect", "non-scaling-stroke");
      if (dash) node.setAttribute("stroke-dasharray", dash);
      node.setAttribute("stroke-linecap", "round");
      node.setAttribute("stroke-linejoin", "round");
      return node;
    };
    let figure;
    if (item.shapeKind === "ellipse") {
      figure = document.createElementNS(ns, "ellipse");
      figure.setAttribute("cx", "50"); figure.setAttribute("cy", "50"); figure.setAttribute("rx", "47"); figure.setAttribute("ry", "47");
      styleShape(figure);
      svg.appendChild(figure);
    } else if (item.shapeKind === "line" || item.shapeKind === "arrow") {
      figure = document.createElementNS(ns, "line");
      figure.setAttribute("x1", "4"); figure.setAttribute("y1", "50"); figure.setAttribute("x2", item.shapeKind === "arrow" ? "82" : "96"); figure.setAttribute("y2", "50");
      styleShape(figure, "none");
      svg.appendChild(figure);
      if (item.shapeKind === "arrow") {
        const head = document.createElementNS(ns, "polygon");
        head.setAttribute("points", "80,34 98,50 80,66");
        styleShape(head, item.shapeStroke);
        svg.appendChild(head);
      }
    } else if (item.shapeKind === "triangle" || item.shapeKind === "diamond") {
      figure = document.createElementNS(ns, "polygon");
      figure.setAttribute("points", item.shapeKind === "triangle" ? "50,3 97,96 3,96" : "50,3 97,50 50,97 3,50");
      styleShape(figure);
      svg.appendChild(figure);
    } else {
      figure = document.createElementNS(ns, "rect");
      figure.setAttribute("x", "3"); figure.setAttribute("y", "3"); figure.setAttribute("width", "94"); figure.setAttribute("height", "94");
      if (item.shapeKind === "rounded") { figure.setAttribute("rx", "14"); figure.setAttribute("ry", "14"); }
      styleShape(figure);
      svg.appendChild(figure);
    }
    return svg;
  };

  const chartPalette = ["#4f72d8", "#579087", "#b07a42", "#7562a7", "#cf5963", "#4c91a8", "#9cab3d", "#d38b5d"];
  const svgNode = (name, attributes = {}) => {
    const node = document.createElementNS("http://www.w3.org/2000/svg", name);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
  };
  const shortLabel = (value, length = 22) => {
    const text = String(value || "KPI");
    return text.length > length ? `${text.slice(0, length - 1)}…` : text;
  };
  const appendSvgText = (svg, text, x, y, attributes = {}) => {
    const node = svgNode("text", { x, y, ...attributes });
    node.textContent = text;
    svg.appendChild(node);
    return node;
  };

  const chartSvg = (item) => {
    const svg = svgNode("svg", { viewBox: "0 0 640 330", preserveAspectRatio: "none", role: "img", "aria-label": item.content || "Gráfico KPI" });
    const activePalette = Array.isArray(item.chartColors) && item.chartColors.length ? item.chartColors : chartPalette;
    const datasets = (item.datasets || []).map((dataset, index) => ({
      ...dataset,
      color: activePalette[index % activePalette.length],
      series: (dataset.series || []).map((entry) => ({ ...entry, value: Math.max(0, Number(entry.value || 0)) })),
    }));
    let series = (item.series || []).map((entry, index) => ({ ...entry, value: Math.max(0, Number(entry.value || 0)), color: activePalette[index % activePalette.length] }));
    if (datasets.length > 1 && item.chartKind === "donut") {
      series = datasets.map((dataset) => ({ label: dataset.label, value: dataset.series.reduce((sum, entry) => sum + entry.value, 0), unit: dataset.unit, color: dataset.color }));
    }
    const total = series.reduce((sum, entry) => sum + entry.value, 0);
    const max = Math.max(1, ...series.map((entry) => entry.value));
    const textColor = item.color || "#17243b";
    svg.style.fontFamily = item.fontFamily || "Arial, sans-serif";
    const datasetTotal = datasets.reduce((sum, dataset) => sum + dataset.series.reduce((datasetSum, entry) => datasetSum + entry.value, 0), 0);
    if ((!series.length && !datasets.length) || (total <= 0 && datasetTotal <= 0)) {
      appendSvgText(svg, "Sin datos para graficar", 320, 170, { "text-anchor": "middle", fill: textColor, "font-size": 18, opacity: .65 });
      return svg;
    }

    if (item.chartKind === "donut") {
      const isSinglePercentage = series.length === 1 && series[0].unit === "%";
      const donutSeries = isSinglePercentage
        ? [series[0], { label: "Restante", value: Math.max(0, 100 - series[0].value), unit: "%", color: "#e9edf2" }]
        : series;
      const donutTotal = donutSeries.reduce((sum, entry) => sum + entry.value, 0) || 1;
      const legend = item.legendPosition || "right";
      const center = legend === "right" ? { x: 205, y: 160 }
        : legend === "left" ? { x: 435, y: 160 }
          : legend === "top" ? { x: 320, y: 190 }
            : legend === "bottom" ? { x: 320, y: 125 }
              : { x: 320, y: 160 };
      const circumference = 2 * Math.PI * 82;
      let offset = 0;
      svg.appendChild(svgNode("circle", { cx: center.x, cy: center.y, r: 82, fill: "none", stroke: "#e9edf2", "stroke-width": 38 }));
      donutSeries.forEach((entry) => {
        const length = (entry.value / donutTotal) * circumference;
        svg.appendChild(svgNode("circle", { cx: center.x, cy: center.y, r: 82, fill: "none", stroke: entry.color, "stroke-width": 38, "stroke-dasharray": `${length} ${circumference - length}`, "stroke-dashoffset": -offset, transform: `rotate(-90 ${center.x} ${center.y})` }));
        offset += length;
      });
      appendSvgText(svg, isSinglePercentage ? `${formatMetricNumber(series[0].value)}%` : formatMetricNumber(total), center.x, center.y - 6, { "text-anchor": "middle", fill: textColor, "font-size": 30, "font-weight": 800 });
      appendSvgText(svg, isSinglePercentage ? "CUMPLIMIENTO" : "TOTAL", center.x, center.y + 20, { "text-anchor": "middle", fill: textColor, "font-size": 11, "font-weight": 700, opacity: .58, "letter-spacing": 1.5 });
      if (legend !== "none") donutSeries.slice(0, 8).forEach((entry, index) => {
        const horizontal = legend === "top" || legend === "bottom";
        const x = horizontal ? 25 + (index % 4) * 153 : (legend === "left" ? 20 : 365);
        const y = horizontal ? (legend === "top" ? 28 : 278) + Math.floor(index / 4) * 24 : 58 + index * 31;
        svg.appendChild(svgNode("rect", { x, y: y - 10, width: 11, height: 11, rx: 3, fill: entry.color }));
        appendSvgText(svg, shortLabel(entry.label, horizontal ? 14 : 22), x + 19, y, { fill: textColor, "font-size": horizontal ? 9 : 11, "font-weight": 650 });
        if (!horizontal) appendSvgText(svg, formatMetricNumber(entry.value), x + 238, y, { "text-anchor": "end", fill: textColor, "font-size": 11, "font-weight": 800 });
      });
      return svg;
    }

    if (datasets.length > 1) {
      const categorySet = new Set();
      datasets.forEach((dataset) => dataset.series.forEach((entry) => categorySet.add(entry.label)));
      const categories = Array.from(categorySet).sort((a, b) => String(a).localeCompare(String(b), "es"));
      const valuesByDataset = datasets.map((dataset) => new Map(dataset.series.map((entry) => [entry.label, entry.value])));
      const multiMax = Math.max(1, ...datasets.flatMap((dataset) => dataset.series.map((entry) => entry.value)));
      const legend = item.legendPosition || "top";
      const plot = {
        left: legend === "left" ? 155 : 62,
        right: legend === "right" ? 490 : 615,
        top: legend === "top" ? 66 : 32,
        bottom: legend === "bottom" ? 232 : 265,
      };
      if (legend !== "none") datasets.slice(0, 8).forEach((dataset, index) => {
        const horizontal = legend === "top" || legend === "bottom";
        const x = horizontal ? 42 + (index % 4) * 145 : (legend === "left" ? 16 : 505);
        const y = horizontal ? (legend === "top" ? 24 : 286) + Math.floor(index / 4) * 22 : 65 + index * 28;
        svg.appendChild(svgNode("rect", { x, y: y - 10, width: 11, height: 11, rx: 3, fill: dataset.color }));
        appendSvgText(svg, shortLabel(dataset.label, horizontal ? 15 : 16), x + 18, y, { fill: textColor, "font-size": 9, "font-weight": 700 });
      });
      [0, .25, .5, .75, 1].forEach((tick) => {
        const y = plot.bottom - (plot.bottom - plot.top) * tick;
        svg.appendChild(svgNode("line", { x1: plot.left, y1: y, x2: plot.right, y2: y, stroke: "#dfe4eb", "stroke-width": 1 }));
        appendSvgText(svg, formatMetricNumber(multiMax * tick), plot.left - 10, y + 4, { "text-anchor": "end", fill: textColor, "font-size": 10, opacity: .55 });
      });
      const categoryStep = (plot.right - plot.left) / Math.max(1, categories.length);
      const labelEvery = Math.max(1, Math.ceil(categories.length / 9));
      if (item.chartKind === "line") {
        datasets.forEach((dataset, datasetIndex) => {
          const points = categories.map((category, index) => ({ x: plot.left + categoryStep * (index + .5), y: plot.bottom - ((valuesByDataset[datasetIndex].get(category) || 0) / multiMax) * (plot.bottom - plot.top) }));
          svg.appendChild(svgNode("polyline", { points: points.map((point) => `${point.x},${point.y}`).join(" "), fill: "none", stroke: dataset.color, "stroke-width": 4, "stroke-linecap": "round", "stroke-linejoin": "round" }));
          points.forEach((point) => svg.appendChild(svgNode("circle", { cx: point.x, cy: point.y, r: 5, fill: dataset.color, stroke: "#fff", "stroke-width": 2 })));
        });
      } else {
        const groupWidth = categoryStep * .78;
        const barWidth = Math.max(3, Math.min(34, groupWidth / datasets.length));
        categories.forEach((category, categoryIndex) => datasets.forEach((dataset, datasetIndex) => {
          const value = valuesByDataset[datasetIndex].get(category) || 0;
          const height = (value / multiMax) * (plot.bottom - plot.top);
          const groupStart = plot.left + categoryStep * categoryIndex + (categoryStep - barWidth * datasets.length) / 2;
          const x = groupStart + datasetIndex * barWidth;
          const y = plot.bottom - height;
          svg.appendChild(svgNode("rect", { x, y, width: Math.max(2, barWidth - 2), height, rx: 4, fill: dataset.color }));
          if (categories.length * datasets.length <= 20 && value > 0) appendSvgText(svg, formatMetricNumber(value), x + (barWidth - 2) / 2, Math.max(16, y - 7), { "text-anchor": "middle", fill: textColor, "font-size": 9, "font-weight": 800 });
        }));
      }
      categories.forEach((category, index) => {
        if (index % labelEvery === 0 || index === categories.length - 1) appendSvgText(svg, shortLabel(category, 13), plot.left + categoryStep * (index + .5), plot.bottom + 28, { "text-anchor": "middle", fill: textColor, "font-size": 9, opacity: .72 });
      });
      return svg;
    }

    const plot = { left: 62, right: 615, top: 32, bottom: 265 };
    [0, .25, .5, .75, 1].forEach((tick) => {
      const y = plot.bottom - (plot.bottom - plot.top) * tick;
      svg.appendChild(svgNode("line", { x1: plot.left, y1: y, x2: plot.right, y2: y, stroke: "#dfe4eb", "stroke-width": 1 }));
      appendSvgText(svg, formatMetricNumber(max * tick), plot.left - 10, y + 4, { "text-anchor": "end", fill: textColor, "font-size": 10, opacity: .55 });
    });
    const step = (plot.right - plot.left) / series.length;
    const labelEvery = Math.max(1, Math.ceil(series.length / 10));
    if (item.chartKind === "line") {
      const points = series.map((entry, index) => ({ x: plot.left + step * (index + .5), y: plot.bottom - (entry.value / max) * (plot.bottom - plot.top), entry }));
      svg.appendChild(svgNode("polyline", { points: points.map((point) => `${point.x},${point.y}`).join(" "), fill: "none", stroke: activePalette[0], "stroke-width": 5, "stroke-linecap": "round", "stroke-linejoin": "round" }));
      points.forEach(({ x, y, entry }, index) => {
        svg.appendChild(svgNode("circle", { cx: x, cy: y, r: 7, fill: entry.color, stroke: "#fff", "stroke-width": 3 }));
        if (series.length <= 14) appendSvgText(svg, formatMetricNumber(entry.value), x, Math.max(18, y - 13), { "text-anchor": "middle", fill: textColor, "font-size": 11, "font-weight": 800 });
        if (index % labelEvery === 0 || index === series.length - 1) appendSvgText(svg, shortLabel(entry.label, 13), x, 294, { "text-anchor": "middle", fill: textColor, "font-size": 9, opacity: .72 });
      });
    } else {
      series.forEach((entry, index) => {
        const barWidth = Math.min(62, step * .58);
        const height = (entry.value / max) * (plot.bottom - plot.top);
        const x = plot.left + step * (index + .5) - barWidth / 2;
        const y = plot.bottom - height;
        svg.appendChild(svgNode("rect", { x, y, width: barWidth, height, rx: 7, fill: entry.color }));
        if (series.length <= 18) appendSvgText(svg, formatMetricNumber(entry.value), x + barWidth / 2, Math.max(18, y - 10), { "text-anchor": "middle", fill: textColor, "font-size": 11, "font-weight": 800 });
        if (index % labelEvery === 0 || index === series.length - 1) appendSvgText(svg, shortLabel(entry.label, 13), x + barWidth / 2, 294, { "text-anchor": "middle", fill: textColor, "font-size": 9, opacity: .72 });
      });
    }
    return svg;
  };

  const canvasElement = (item) => {
    const node = document.createElement("div");
    node.className = `canvas-element type-${item.type}${selectedId === item.id ? " is-selected" : ""}`;
    node.dataset.elementId = item.id;
    Object.assign(node.style, { left: `${item.x}px`, top: `${item.y}px`, width: `${item.width}px`, height: `${item.height}px`, zIndex: String(item.z), borderRadius: `${item.radius}px` });
    if (item.type === "shape") node.style.transform = `rotate(${Number(item.rotation || 0)}deg)`;
    if (item.type === "image") {
      const img = document.createElement("img");
      img.src = item.src;
      img.alt = item.content || "Imagen del dashboard";
      img.draggable = false;
      img.style.objectFit = item.objectFit;
      node.appendChild(img);
    } else if (item.type === "shape") {
      node.appendChild(shapeSvg(item));
    } else if (item.type === "data_text") {
      const content = document.createElement("div");
      content.className = "element-content data-text-content";
      const label = document.createElement("strong"); label.textContent = item.content || "Dato";
      const value = document.createElement("span"); value.textContent = item.dataValue || "Sin datos";
      content.append(label, value);
      Object.assign(content.style, { fontFamily: item.fontFamily, fontSize: `${item.fontSize}px`, fontWeight: item.fontWeight, color: item.color, background: item.background, textAlign: item.align });
      node.appendChild(content);
    } else if (item.type === "data_card") {
      const content = document.createElement("div");
      content.className = "element-content kpi-content";
      const label = document.createElement("span"); label.className = "kpi-label"; label.textContent = item.content || "Dato";
      const value = document.createElement("strong"); value.className = "kpi-value"; value.textContent = item.dataValue || "0";
      const detail = document.createElement("small"); detail.className = "kpi-detail"; detail.textContent = item.dataDetail || "Valores distintos";
      content.append(label, value, detail);
      Object.assign(content.style, { fontFamily: item.fontFamily, fontSize: `${item.fontSize}px`, fontWeight: item.fontWeight, color: item.color, background: item.background, textAlign: item.align });
      node.appendChild(content);
    } else if (item.type === "data_table") {
      const content = document.createElement("div");
      content.className = "element-content data-table-content";
      const table = document.createElement("table");
      const head = document.createElement("thead");
      const headRow = document.createElement("tr");
      (item.dataColumns || []).forEach((column) => { const th = document.createElement("th"); th.textContent = column.label; headRow.appendChild(th); });
      head.appendChild(headRow);
      const body = document.createElement("tbody");
      (item.dataRows || []).slice(0, 20).forEach((row) => {
        const tr = document.createElement("tr");
        (item.dataColumns || []).forEach((column) => { const td = document.createElement("td"); td.textContent = row[column.id] ?? ""; tr.appendChild(td); });
        body.appendChild(tr);
      });
      table.append(head, body);
      content.appendChild(table);
      Object.assign(content.style, { fontFamily: item.fontFamily, fontSize: `${item.fontSize}px`, color: item.color, background: item.background });
      node.appendChild(content);
    } else if (item.type === "date_filter") {
      const content = document.createElement("div");
      content.className = "element-content canvas-date-filter";
      const title = document.createElement("strong");
      title.textContent = item.content || "Fecha de visita";
      const range = document.createElement("div");
      const from = document.createElement("span");
      const to = document.createElement("span");
      const fromLabel = document.createElement("small"); fromLabel.textContent = "Desde";
      const fromValue = document.createElement("b"); fromValue.textContent = item.dateFrom || "Seleccionar";
      const toLabel = document.createElement("small"); toLabel.textContent = "Hasta";
      const toValue = document.createElement("b"); toValue.textContent = item.dateTo || "Seleccionar";
      from.append(fromLabel, fromValue);
      to.append(toLabel, toValue);
      range.append(from, to);
      content.append(title, range);
      Object.assign(content.style, { fontFamily: item.fontFamily, fontSize: `${item.fontSize}px`, color: item.color, background: item.background });
      node.appendChild(content);
    } else if (item.type === "kpi") {
      const content = document.createElement("div");
      content.className = "element-content kpi-content";
      const label = document.createElement("span");
      label.className = "kpi-label";
      label.textContent = item.content;
      const value = document.createElement("strong");
      value.className = "kpi-value";
      value.textContent = item.kpiValue || "0";
      const detail = document.createElement("small");
      detail.className = "kpi-detail";
      detail.textContent = item.kpiDetail || "Locales distintos";
      content.append(label, value, detail);
      Object.assign(content.style, { fontFamily: item.fontFamily, fontSize: `${item.fontSize}px`, fontWeight: item.fontWeight, color: item.color, background: item.background, textAlign: item.align });
      node.appendChild(content);
    } else if (item.type === "chart") {
      const content = document.createElement("div");
      content.className = "element-content chart-content";
      const title = document.createElement("strong");
      title.className = "chart-title";
      title.textContent = item.content || "Gráfico KPI";
      content.append(title, chartSvg(item));
      Object.assign(content.style, { fontFamily: item.fontFamily, fontSize: `${item.fontSize}px`, fontWeight: item.fontWeight, color: item.color, background: item.background, textAlign: item.align });
      node.appendChild(content);
    } else {
      const content = document.createElement("div");
      content.className = "element-content";
      content.textContent = item.content;
      Object.assign(content.style, { fontFamily: item.fontFamily, fontSize: `${item.fontSize}px`, fontWeight: item.fontWeight, color: item.color, background: item.background, textAlign: item.align });
      node.appendChild(content);
    }
    ["nw", "ne", "sw", "se"].forEach((position) => {
      const handle = document.createElement("button");
      handle.type = "button";
      handle.className = `resize-handle handle-${position}`;
      handle.dataset.resize = position;
      handle.setAttribute("aria-label", `Redimensionar ${position}`);
      node.appendChild(handle);
    });
    return node;
  };

  const renderElements = () => {
    canvas.replaceChildren(...elements.map(canvasElement));
  };

  const selectedElement = () => elements.find((item) => item.id === selectedId) || null;
  const cloneElementData = (item) => JSON.parse(JSON.stringify(item));
  const isEditableTarget = (target) => Boolean(
    target instanceof Element
    && target.closest('input, textarea, select, [contenteditable="true"], [contenteditable=""]')
  );
  const field = (id, value) => { const node = $(id); if (node) node.value = value ?? ""; };

  const syncProperties = () => {
    const item = selectedElement();
    $("no-selection").hidden = Boolean(item);
    $("properties-form").hidden = !item;
    $("delete-element").disabled = !item;
    if (!item) return;
    field("prop-content", item.content);
    field("prop-x", Math.round(item.x)); field("prop-y", Math.round(item.y)); field("prop-width", Math.round(item.width)); field("prop-height", Math.round(item.height));
    field("prop-font", item.fontFamily); field("prop-font-size", item.fontSize); field("prop-font-weight", item.fontWeight); field("prop-color", item.color); field("prop-background", item.background);
    field("prop-date-from", item.dateFrom); field("prop-date-to", item.dateTo);
    field("prop-chart-kind", item.chartKind);
    field("prop-legend-position", item.legendPosition);
    const colors = Array.isArray(item.chartColors) ? item.chartColors : chartPalette;
    field("prop-chart-color-1", colors[0] || chartPalette[0]); field("prop-chart-color-2", colors[1] || chartPalette[1]);
    field("prop-chart-color-3", colors[2] || chartPalette[2]); field("prop-chart-color-4", colors[3] || chartPalette[3]);
    field("prop-shape-fill", item.shapeFill); field("prop-shape-stroke", item.shapeStroke); field("prop-shape-stroke-width", item.shapeStrokeWidth); field("prop-shape-stroke-style", item.shapeStrokeStyle); field("prop-shape-rotation", item.rotation);
    field("prop-align", item.align); field("prop-object-fit", item.objectFit); field("prop-radius", item.radius);
    $("radius-output").textContent = `${item.radius} px`;
    $("shape-rotation-output").textContent = `${Number(item.rotation || 0)}°`;
    document.querySelectorAll(".text-property").forEach((node) => { node.hidden = item.type === "image" || item.type === "shape"; });
    document.querySelectorAll(".image-property").forEach((node) => { node.hidden = item.type !== "image"; });
    document.querySelectorAll(".date-filter-property").forEach((node) => { node.hidden = item.type !== "date_filter"; });
    document.querySelectorAll(".shape-property").forEach((node) => { node.hidden = item.type !== "shape"; });
    document.querySelectorAll(".chart-property").forEach((node) => { node.hidden = item.type !== "chart"; });
  };

  const selectElement = (id) => {
    selectedId = id;
    renderElements();
    syncProperties();
  };

  const updateSelected = (changes, rerender = true) => {
    const item = selectedElement();
    if (!item) return;
    Object.assign(item, changes);
    if (rerender) renderElements();
    saveDraft();
  };

  const removeSelectedElement = () => {
    if (!selectedId) return false;
    elements = elements.filter((item) => item.id !== selectedId);
    selectedId = null;
    renderElements();
    syncProperties();
    saveDraft();
    return true;
  };

  const copySelectedElement = () => {
    const item = selectedElement();
    if (!item) return false;
    copiedElement = cloneElementData(item);
    delete copiedElement.id;
    pasteSequence = 0;
    showToast("Elemento copiado. Usa Ctrl+V para pegarlo.", "neutral");
    return true;
  };

  const pasteCopiedElement = () => {
    if (!copiedElement) return false;
    pasteSequence += 1;
    const copy = cloneElementData(copiedElement);
    delete copy.id;
    const offset = 24 * pasteSequence;
    const canvasWidth = Math.max(640, Number($("canvas-width").value) || 1200);
    const canvasHeight = Math.max(480, Number($("canvas-height").value) || 780);
    copy.x = Math.min(Math.max(0, Number(copy.x || 0) + offset), Math.max(0, canvasWidth - Number(copy.width || 0)));
    copy.y = Math.min(Math.max(0, Number(copy.y || 0) + offset), Math.max(0, canvasHeight - Number(copy.height || 0)));
    copy.z = zIndex + 1;
    createElement(copy);
    showToast("Copia pegada en el lienzo.");
    return true;
  };

  const initializeCanvas = () => {
    elements = [];
    selectedId = null;
    nextId = 1;
    zIndex = 1;
    createElement({ type: "image", x: 20, y: 20, width: 1160, height: 500, content: `Plantilla ${selectedTemplate.name}`, src: selectedTemplate.image, objectFit: "contain", radius: 10, z: 1 });
    createElement({ type: "text", x: 54, y: 548, width: 650, height: 74, content: `PERFECT STORE · ${selectedTemplate.name.toUpperCase()}`, fontSize: 34, fontWeight: "900", color: "#17243b", background: "transparent", radius: 0, z: 2 });
    selectedId = null;
    renderElements();
    syncProperties();
  };

  const formatMetricNumber = (value) => new Intl.NumberFormat("es-CL", { maximumFractionDigits: 2 }).format(Number(value || 0));

  const addInformationKpi = (item) => createElement({
    type: "kpi",
    x: 80 + (elements.length % 4) * 30,
    y: 650 + (elements.length % 5) * 18,
    width: 320,
    height: 145,
    content: item.label,
    kpiValue: `${formatMetricNumber(item.value)} ${item.unit}`,
    kpiDetail: `Conteo distintivo · ${item.field}`,
    fontSize: 15,
    fontWeight: "700",
    color: "#ffffff",
    background: "#62518f",
    align: "left",
    radius: 14,
    source: { type: "information_kpi", metric: item.id, field: item.field, value: Number(item.value || 0), report_date_from: scope?.date_from || "" },
    metricValue: Number(item.value || 0),
    metricUnit: item.unit,
  });

  const createSourceCard = (item, kind) => {
    const isState = kind === "state";
    const isMaterial = kind === "material";
    const isInformation = kind === "information";
    const isData = kind === "data";
    const isSurvey = kind === "survey";
    const label = isSurvey ? item.question_text : item.label;
    const card = document.createElement("article");
    card.className = `question-card source-card ${isState ? "state-card" : isMaterial ? "material-card" : isInformation || isData ? "information-card" : "survey-card"}`;
    const meta = document.createElement("div");
    meta.className = "question-meta";
    const source = document.createElement("span"); source.textContent = isSurvey ? "Encuesta" : isInformation ? "Información" : isData ? "Datos" : item.group;
    const usage = document.createElement("small");
    usage.textContent = isSurvey
      ? `${Number(item.formularios || 0).toLocaleString("es-CL")} formularios`
      : isInformation
        ? `${formatMetricNumber(item.value)} ${item.unit}`
        : isData
          ? item.group
        : `${Number(item.locales || 0).toLocaleString("es-CL")} locales distintos`;
    meta.append(source, usage);
    const name = document.createElement("strong"); name.textContent = label;
    card.append(meta, name);
    if (isMaterial) {
      const values = document.createElement("p");
      values.className = "material-values";
      values.textContent = `Implementado ${formatMetricNumber(item.implemented_value)} · Planificado ${formatMetricNumber(item.planned_value)}`;
      card.appendChild(values);
    }
    if (isSurvey && item.opciones?.length) {
      const samples = document.createElement("p");
      samples.className = "answer-samples";
      samples.textContent = item.opciones.slice(0, 3).join(" · ");
      card.appendChild(samples);
    }
    const add = document.createElement("button");
    add.type = "button";
    add.textContent = isData ? "+ Usar este dato" : "+ Agregar como KPI";
    add.addEventListener("click", () => {
      if (isSurvey) {
        openKpiConfigurator(item);
        return;
      }
      if (isInformation) {
        addInformationKpi(item);
        showToast(`${item.label} agregado al lienzo.`);
        return;
      }
      if (isData) {
        openDataConfigurator(item);
        return;
      }
      openStateKpiConfigurator(item, isMaterial ? "material" : "state");
    });
    card.appendChild(add);
    return card;
  };

  const createAccordion = (title, countLabel, open, body) => {
    const details = document.createElement("details");
    details.className = "source-accordion";
    details.open = open;
    const summary = document.createElement("summary");
    const copy = document.createElement("span");
    const heading = document.createElement("strong"); heading.textContent = title;
    const count = document.createElement("small"); count.textContent = countLabel;
    copy.append(heading, count);
    const arrow = document.createElement("i"); arrow.textContent = "⌄";
    summary.append(copy, arrow);
    details.append(summary, body);
    return details;
  };

  const renderQuestions = () => {
    const list = $("question-list");
    const term = $("question-search").value.trim().toLowerCase();
    const filteredStates = states.filter((item) => `${item.group} ${item.label}`.toLowerCase().includes(term));
    const filteredMaterials = materials.filter((item) => item.label.toLowerCase().includes(term));
    const filteredInformation = information.filter((item) => item.label.toLowerCase().includes(term));
    const filteredData = dataFields.filter((item) => `${item.group} ${item.label}`.toLowerCase().includes(term));
    const filteredSurvey = surveyQuestions.filter((item) => item.question_text.toLowerCase().includes(term));
    list.replaceChildren();
    if (!filteredStates.length && !filteredMaterials.length && !filteredInformation.length && !filteredData.length && !filteredSurvey.length) {
      const empty = document.createElement("div");
      empty.className = "empty-state";
      empty.innerHTML = "<span>0</span><strong>Sin resultados</strong><small>Prueba con otra búsqueda.</small>";
      list.appendChild(empty);
      return;
    }

    if (filteredStates.length) {
      const body = document.createElement("div");
      body.className = "accordion-body";
      const grouped = Object.groupBy
        ? Object.groupBy(filteredStates, (item) => item.group)
        : filteredStates.reduce((result, item) => { (result[item.group] ||= []).push(item); return result; }, {});
      Object.entries(grouped).forEach(([group, items]) => {
        const section = document.createElement("section");
        section.className = "state-subgroup";
        const heading = document.createElement("h3"); heading.textContent = group;
        section.appendChild(heading);
        items.forEach((item) => section.appendChild(createSourceCard(item, "state")));
        body.appendChild(section);
      });
      list.appendChild(createAccordion("Estados", `${filteredStates.length} opciones`, true, body));
    }

    if (filteredMaterials.length) {
      const body = document.createElement("div");
      body.className = "accordion-body";
      filteredMaterials.forEach((item) => body.appendChild(createSourceCard(item, "material")));
      list.appendChild(createAccordion("Materiales", `${filteredMaterials.length} materiales`, Boolean(term), body));
    }

    if (filteredInformation.length) {
      const body = document.createElement("div");
      body.className = "accordion-body";
      filteredInformation.forEach((item) => body.appendChild(createSourceCard(item, "information")));
      list.appendChild(createAccordion("Información", `${filteredInformation.length} indicadores`, Boolean(term), body));
    }

    if (filteredData.length) {
      const body = document.createElement("div");
      body.className = "accordion-body";
      filteredData.forEach((item) => body.appendChild(createSourceCard(item, "data")));
      list.appendChild(createAccordion("Datos", `${filteredData.length} campos`, Boolean(term), body));
    }

    if (filteredSurvey.length) {
      const body = document.createElement("div");
      body.className = "accordion-body";
      filteredSurvey.forEach((item) => body.appendChild(createSourceCard(item, "survey")));
      list.appendChild(createAccordion("Encuestas", `${filteredSurvey.length} preguntas`, Boolean(term), body));
    }
  };

  $("question-search").addEventListener("input", renderQuestions);

  const selectedDataDisplayType = () => document.querySelector('input[name="data-display-type"]:checked')?.value || "text";
  const selectedDataFields = () => Array.from(document.querySelectorAll('#data-table-options input[type="checkbox"]:checked')).map((input) => input.value);
  const syncDataDisplayOptions = () => {
    const isTable = selectedDataDisplayType() === "table";
    $("data-table-fieldset").hidden = !isTable;
    $("create-data-element").disabled = isTable && selectedDataFields().length === 0;
  };
  const closeDataConfigurator = () => {
    dataModal.classList.remove("show");
    window.setTimeout(() => { dataModal.hidden = true; }, 180);
    pendingDataField = null;
    $("data-feedback").textContent = "";
  };
  const openDataConfigurator = (item) => {
    pendingDataField = item;
    $("data-field-label").textContent = `${item.group} · ${item.label}`;
    $("data-feedback").textContent = "";
    const defaultType = document.querySelector('input[name="data-display-type"][value="text"]');
    if (defaultType) defaultType.checked = true;
    const options = $("data-table-options");
    options.replaceChildren();
    dataFields.forEach((fieldItem) => {
      const label = document.createElement("label"); label.className = "answer-option";
      const input = document.createElement("input"); input.type = "checkbox"; input.value = fieldItem.id; input.checked = fieldItem.id === item.id;
      input.addEventListener("change", () => {
        const selected = selectedDataFields();
        if (selected.length > 8) { input.checked = false; showToast("La tabla admite un máximo de 8 columnas.", "neutral"); }
        syncDataDisplayOptions();
      });
      const copy = document.createElement("span");
      const name = document.createElement("strong"); name.textContent = fieldItem.label;
      const group = document.createElement("small"); group.textContent = fieldItem.group;
      copy.append(name, group); label.append(input, copy); options.appendChild(label);
    });
    syncDataDisplayOptions();
    dataModal.hidden = false;
    requestAnimationFrame(() => dataModal.classList.add("show"));
  };

  document.querySelectorAll('input[name="data-display-type"]').forEach((input) => input.addEventListener("change", syncDataDisplayOptions));
  $("cancel-data").addEventListener("click", closeDataConfigurator);
  $("create-data-element").addEventListener("click", async () => {
    if (!pendingDataField || !scope) return;
    const displayType = selectedDataDisplayType();
    const fieldIds = displayType === "table" ? selectedDataFields() : [pendingDataField.id];
    if (!fieldIds.length) return;
    const button = $("create-data-element");
    button.disabled = true;
    button.textContent = "Consultando datos...";
    $("data-feedback").textContent = "";
    const form = new FormData();
    form.append("action", "data_preview");
    form.append("csrf_token", config.csrf);
    form.append("id_division", scope.id_division);
    form.append("id_subdivision", scope.id_subdivision);
    form.append("id_formulario", scope.id_formulario || 0);
    form.append("date_from", scope.date_from);
    fieldIds.forEach((fieldId) => form.append("fields[]", fieldId));
    try {
      const data = await apiRequest(config.api, { method: "POST", body: form });
      const baseSource = { type: "data_field", fields: fieldIds, report_date_from: scope.date_from, limited: Boolean(data.limited) };
      if (displayType === "text") {
        createElement({
          type: "data_text", x: 90, y: 650 + (elements.length % 5) * 22, width: 440, height: 72,
          content: pendingDataField.label, dataValue: data.sample || "Sin datos", fontSize: 17, fontWeight: "700",
          color: "#17243b", background: "#ffffff", align: "left", radius: 10, source: baseSource,
        });
      } else if (displayType === "card") {
        createElement({
          type: "data_card", x: 90, y: 650 + (elements.length % 5) * 22, width: 320, height: 145,
          content: pendingDataField.label, dataValue: Number(data.distinct_count || 0).toLocaleString("es-CL"),
          dataDetail: data.sample ? `Valores distintos · Ejemplo: ${data.sample}` : "Valores distintos",
          fontSize: 15, fontWeight: "700", color: "#ffffff", background: "#7562a7", align: "left", radius: 14,
          source: { ...baseSource, metric: "distinct", value: Number(data.distinct_count || 0) },
        });
      } else {
        createElement({
          type: "data_table", x: 80, y: 650 + (elements.length % 4) * 24,
          width: Math.min(1100, Math.max(520, fieldIds.length * 185)), height: 360,
          content: `Tabla · ${data.columns.map((column) => column.label).join(" · ")}`,
          dataColumns: data.columns || [], dataRows: data.rows || [], fontSize: 14, fontWeight: "400",
          color: "#344156", background: "#ffffff", align: "left", radius: 8, source: baseSource,
        });
      }
      const addedLabel = pendingDataField.label;
      closeDataConfigurator();
      showToast(`${addedLabel} agregado al lienzo.`);
    } catch (error) {
      $("data-feedback").textContent = error.message;
    } finally {
      button.innerHTML = 'Agregar al lienzo <span>→</span>';
      syncDataDisplayOptions();
    }
  });

  const closeKpiConfigurator = () => {
    kpiModal.classList.remove("show");
    window.setTimeout(() => { kpiModal.hidden = true; }, 180);
    pendingQuestion = null;
    pendingQuestionDetail = null;
    pendingState = null;
  };

  const selectedKpiAnswers = () => Array.from(document.querySelectorAll('#kpi-answer-options input[type="checkbox"]:checked')).map((input) => input.value);

  const validateKpiConfiguration = () => {
    const requiresAnswers = !pendingState && pendingQuestionDetail && pendingQuestionDetail.type !== "numeric";
    $("create-kpi").disabled = (!pendingState && !pendingQuestionDetail) || (requiresAnswers && selectedKpiAnswers().length === 0);
    $("kpi-feedback").textContent = "";
  };

  const updateKpiCountingNote = () => {
    const metric = $("kpi-metric").value;
    const labels = {
      distinct_local: ["Conteo distintivo", "Cada local se cuenta una sola vez mediante id_local."],
      ratio_visits: ["Ratio sobre visitas", "Locales distintos que cumplen la respuesta dividido por el total de locales visitados."],
      ratio_planned: ["Ratio sobre planificados", "Locales distintos del estado o material dividido por el total de locales planificados."],
      material_implemented: ["Cantidad implementada", "Suma del campo valor para el material seleccionado."],
      material_planned: ["Cantidad planificada", "Suma del campo valor_propuesto para el material seleccionado."],
      material_ratio: ["Cumplimiento del material", "Cantidad implementada dividida por la cantidad planificada."],
      average: ["Promedio", "Se promedian los valores numéricos registrados en el período."],
      sum: ["Suma", "Se suman los valores numéricos registrados en el período."],
      min: ["Valor mínimo", "Se utiliza el menor valor numérico registrado en el período."],
      max: ["Valor máximo", "Se utiliza el mayor valor numérico registrado en el período."],
    };
    const [title, description] = labels[metric] || labels.distinct_local;
    $("kpi-counting-note").querySelector("strong").textContent = title;
    $("kpi-counting-note").querySelector("small").textContent = description;
  };

  const populateKpiMetrics = (type) => {
    const metric = $("kpi-metric");
    metric.replaceChildren();
    const choices = type === "numeric"
      ? [["average", "Promedio"], ["sum", "Suma"], ["min", "Mínimo"], ["max", "Máximo"], ["distinct_local", "Locales distintos"]]
      : [["distinct_local", "Contar locales distintos"], ["ratio_visits", "Ratio sobre total de visitas (%)"]];
    choices.forEach(([value, label]) => metric.add(new Option(label, value)));
    updateKpiCountingNote();
  };

  const populateKpiAnswers = (detail) => {
    const container = $("kpi-answer-options");
    container.replaceChildren();
    const isNumeric = detail.type === "numeric";
    $("kpi-answer-fieldset").hidden = isNumeric;
    if (isNumeric) return;
    if (!detail.options.length) {
      const empty = document.createElement("p");
      empty.className = "answer-option-empty";
      empty.textContent = "Esta pregunta todavía no tiene respuestas cuantificables.";
      container.appendChild(empty);
      return;
    }
    detail.options.forEach((option) => {
      const label = document.createElement("label");
      label.className = "answer-option";
      const input = document.createElement("input");
      input.type = "checkbox";
      input.value = option.label;
      input.addEventListener("change", validateKpiConfiguration);
      const copy = document.createElement("span");
      const name = document.createElement("strong");
      name.textContent = option.label;
      const count = document.createElement("small");
      count.textContent = `${Number(option.locales || 0).toLocaleString("es-CL")} locales · ${Number(option.registros || 0).toLocaleString("es-CL")} respuestas`;
      copy.append(name, count);
      label.append(input, copy);
      container.appendChild(label);
    });
  };

  const openStateKpiConfigurator = (state, catalogKind = "state") => {
    pendingState = { ...state, catalogKind };
    pendingQuestion = null;
    pendingQuestionDetail = null;
    $("kpi-question-label").textContent = `${state.group} · ${state.label}`;
    $("kpi-type-badge").textContent = catalogKind === "material" ? "Material" : "Estado";
    $("kpi-loading").hidden = true;
    $("kpi-config-fields").hidden = false;
    $("kpi-answer-fieldset").hidden = true;
    $("kpi-feedback").textContent = "";
    const metric = $("kpi-metric");
    if (catalogKind === "material") {
      metric.replaceChildren(
        new Option("Cantidad implementada (valor)", "material_implemented"),
        new Option("Cantidad planificada (valor_propuesto)", "material_planned"),
        new Option("Ratio implementado / planificado (%)", "material_ratio"),
      );
    } else {
      metric.replaceChildren(
        new Option("Contar locales distintos", "distinct_local"),
        new Option("Ratio sobre total planificado (%)", "ratio_planned"),
      );
    }
    updateKpiCountingNote();
    validateKpiConfiguration();
    kpiModal.hidden = false;
    requestAnimationFrame(() => kpiModal.classList.add("show"));
  };

  const openKpiConfigurator = async (question) => {
    if (!scope) return;
    pendingState = null;
    pendingQuestion = question;
    pendingQuestionDetail = null;
    $("kpi-question-label").textContent = question.question_text;
    $("kpi-type-badge").textContent = "Detectando";
    $("kpi-loading").hidden = false;
    $("kpi-config-fields").hidden = true;
    $("kpi-feedback").textContent = "";
    $("create-kpi").disabled = true;
    $("kpi-answer-options").replaceChildren();
    kpiModal.hidden = false;
    requestAnimationFrame(() => kpiModal.classList.add("show"));
    try {
      const query = new URLSearchParams({
        action: "question_options",
        id_division: String(scope.id_division),
        id_subdivision: String(scope.id_subdivision),
        id_formulario: String(scope.id_formulario),
        date_from: scope.date_from,
        question_text: question.question_text,
      });
      const detail = await apiRequest(`${config.api}?${query.toString()}`);
      if (pendingQuestion !== question) return;
      pendingQuestionDetail = detail;
      const typeLabels = { boolean: "Sí / No", numeric: "Numérica", multiple: "Selección múltiple" };
      $("kpi-type-badge").textContent = typeLabels[detail.type] || "Encuesta";
      populateKpiMetrics(detail.type);
      populateKpiAnswers(detail);
      $("kpi-loading").hidden = true;
      $("kpi-config-fields").hidden = false;
      validateKpiConfiguration();
    } catch (error) {
      $("kpi-loading").hidden = true;
      $("kpi-feedback").textContent = error.message;
    }
  };

  $("cancel-kpi").addEventListener("click", closeKpiConfigurator);
  $("kpi-metric").addEventListener("change", () => { updateKpiCountingNote(); validateKpiConfiguration(); });
  kpiModal.addEventListener("click", (event) => { if (event.target === kpiModal) closeKpiConfigurator(); });

  $("create-kpi").addEventListener("click", async () => {
    if (pendingState && scope) {
      const state = pendingState;
      const metric = $("kpi-metric").value;
      const isMaterial = state.catalogKind === "material";
      const implementedValue = Number(state.implemented_value || 0);
      const materialPlannedValue = Number(state.planned_value || 0);
      const numerator = isMaterial ? implementedValue : Number(state.locales || 0);
      const denominator = isMaterial ? materialPlannedValue : Number(plannedLocales || 0);
      const isRatio = metric === (isMaterial ? "material_ratio" : "ratio_planned");
      const ratio = denominator > 0 ? (numerator / denominator) * 100 : 0;
      const metricValue = isMaterial && metric === "material_planned" ? materialPlannedValue : numerator;
      const displayValue = isRatio ? `${formatMetricNumber(ratio)}%` : `${formatMetricNumber(metricValue)} ${isMaterial ? "unidades" : "locales"}`;
      createElement({
        type: "kpi",
        x: 80 + (elements.length % 4) * 30,
        y: 650 + (elements.length % 5) * 18,
        width: 320,
        height: 145,
        content: state.label,
        kpiValue: displayValue,
        kpiDetail: isMaterial
          ? `Implementado ${formatMetricNumber(implementedValue)} · Planificado ${formatMetricNumber(materialPlannedValue)}`
          : isRatio
            ? `${formatMetricNumber(numerator)} / ${formatMetricNumber(denominator)} planificados`
            : `${state.group} · conteo distintivo por id_local`,
        fontSize: 15,
        fontWeight: "700",
        color: "#ffffff",
        background: isMaterial ? "#8a5a32" : "#17243b",
        align: "left",
        radius: 14,
        source: {
          type: isMaterial ? "material_kpi" : "state_kpi",
          field: state.field,
          value: state.value,
          group: state.group,
          metric,
          distinct_locales: Number(state.locales || 0),
          planned_locales: isMaterial ? Number(plannedLocales || 0) : denominator,
          implemented_value: isMaterial ? implementedValue : undefined,
          planned_value: isMaterial ? materialPlannedValue : undefined,
          report_date_from: scope.date_from,
        },
        metricValue: isRatio ? ratio : metricValue,
        metricUnit: isRatio ? "%" : (isMaterial ? "unidades" : "locales"),
      });
      closeKpiConfigurator();
      showToast(`KPI de ${isMaterial ? "material" : "estado"} calculado: ${displayValue}.`);
      return;
    }
    if (!pendingQuestion || !pendingQuestionDetail || !scope) return;
    const button = $("create-kpi");
    const answers = selectedKpiAnswers();
    const metric = $("kpi-metric").value;
    const form = new FormData();
    form.append("action", "calculate_kpi");
    form.append("csrf_token", config.csrf);
    form.append("id_division", scope.id_division);
    form.append("id_subdivision", scope.id_subdivision);
    form.append("id_formulario", scope.id_formulario);
    form.append("date_from", scope.date_from);
    form.append("question_text", pendingQuestion.question_text);
    form.append("metric", metric);
    answers.forEach((answer) => form.append("selected_answers[]", answer));
    button.disabled = true;
    button.firstChild.textContent = "Calculando... ";
    $("kpi-feedback").textContent = "";
    try {
      const data = await apiRequest(config.api, { method: "POST", body: form });
      const metricLabels = { distinct_local: "locales distintos", ratio_visits: "ratio sobre visitas", average: "promedio", sum: "suma", min: "mínimo", max: "máximo" };
      const answerLabel = answers.length ? answers.join(" · ") : metricLabels[metric];
      const displayValue = data.unit === "%" ? `${data.formatted}%` : `${data.formatted} ${data.unit}`;
      const calculationDetail = metric === "ratio_visits"
        ? `${answerLabel} · ${Number(data.distinct_locales || 0).toLocaleString("es-CL")} / ${Number(data.denominator_visits || 0).toLocaleString("es-CL")} visitados`
        : answerLabel;
      createElement({
        type: "kpi",
        x: 80 + (elements.length % 4) * 30,
        y: 650 + (elements.length % 5) * 18,
        width: 340,
        height: 150,
        content: pendingQuestion.question_text,
        kpiValue: displayValue,
        kpiDetail: calculationDetail,
        fontSize: 14,
        fontWeight: "700",
        color: "#ffffff",
        background: "#3f6f68",
        align: "left",
        radius: 14,
        source: {
          type: "survey_kpi",
          question_id: pendingQuestion.id,
          question_text: pendingQuestion.question_text,
          question_type: pendingQuestionDetail.type,
          metric,
          selected_answers: answers,
          report_date_from: scope.date_from,
          distinct_locales: data.distinct_locales,
          denominator_visits: data.denominator_visits,
          matched_records: data.matched_records,
        },
        metricValue: Number(data.value || 0),
        metricUnit: data.unit,
      });
      closeKpiConfigurator();
      showToast(`KPI calculado: ${displayValue}.`);
    } catch (error) {
      $("kpi-feedback").textContent = error.message;
    } finally {
      button.innerHTML = 'Calcular y agregar ficha <span>→</span>';
      validateKpiConfiguration();
    }
  });

  $("continue-scope").addEventListener("click", async () => {
    const divisionId = divisionSelect.value;
    const subdivisionId = subdivisionSelect.value;
    const formId = activitySelect.value;
    const dateFrom = scopeDateFrom.value;
    if (!divisionId || !subdivisionId || !formId || !dateFrom) return;
    const button = $("continue-scope");
    button.disabled = true;
    button.textContent = "Cargando estados, materiales y encuestas...";
    $("scope-feedback").textContent = "Consultando estados, materiales y respuestas...";
    try {
      const data = await apiRequest(`${config.api}?action=questions&id_division=${encodeURIComponent(divisionId)}&id_subdivision=${encodeURIComponent(subdivisionId)}&id_formulario=${encodeURIComponent(formId)}&date_from=${encodeURIComponent(dateFrom)}`);
      states = data.states || [];
      materials = data.materials || [];
      information = data.information || [];
      dataFields = data.data_fields || [];
      surveyQuestions = data.survey_questions || [];
      plannedLocales = Number(data.planned_locales || 0);
      scope = { id_division: Number(divisionId), id_subdivision: Number(subdivisionId), id_formulario: Number(formId), division: data.scope.division, subdivision: data.scope.subdivision, activity: data.scope.activity, date_from: dateFrom };
      $("scope-division").textContent = scope.division;
      $("scope-subdivision").textContent = `${scope.subdivision} · ${scope.activity} · Desde ${scope.date_from}`;
      $("question-count").textContent = `${states.length.toLocaleString("es-CL")} estados · ${materials.length.toLocaleString("es-CL")} materiales · ${information.length.toLocaleString("es-CL")} indicadores · ${dataFields.length.toLocaleString("es-CL")} datos · ${surveyQuestions.length.toLocaleString("es-CL")} encuestas`;
      $("editor-template-name").textContent = selectedTemplate.name;
      $("topbar-status").textContent = `${scope.division} · ${scope.subdivision}`;
      renderQuestions();
      initializeCanvas();
      closeScopeModal();
      templateView.hidden = true;
      editorView.hidden = false;
      window.scrollTo({ top: 0, behavior: "smooth" });
      showToast(`${states.length} estados, ${materials.length} materiales, ${information.length} indicadores, ${dataFields.length} datos y ${surveyQuestions.length} preguntas cargados.`);
    } catch (error) {
      $("scope-feedback").textContent = error.message;
    } finally {
      button.innerHTML = 'Crear espacio de trabajo <span>→</span>';
      validateScopeForm();
    }
  });

  const exitEditor = () => {
    editorView.hidden = true;
    templateView.hidden = false;
    $("topbar-status").textContent = `${templates.length} plantillas disponibles`;
    window.scrollTo({ top: 0, behavior: "smooth" });
  };
  $("editor-back").addEventListener("click", exitEditor);
  $("go-home").addEventListener("click", () => { if (!editorView.hidden) exitEditor(); else window.scrollTo({ top: 0, behavior: "smooth" }); });

  $("add-text").addEventListener("click", () => createElement({ type: "text", x: 90, y: 110, width: 420, height: 90, content: "Nuevo título", fontSize: 32, fontWeight: "700", color: "#17243b", background: "transparent", radius: 0 }));

  let chartQuestionDetail = null;
  let selectedChartStateIndexes = new Set();
  const selectedChartAnswers = () => Array.from(document.querySelectorAll('#chart-answer-options input[type="checkbox"]:checked')).map((input) => input.value);
  const selectedChartStates = () => Array.from(selectedChartStateIndexes).map((index) => states[index]).filter(Boolean);
  const selectedChartSource = () => {
    const index = Number($("chart-source-item").value);
    return $("chart-source-kind").value === "survey" ? surveyQuestions[index] : null;
  };
  const validateChartConfiguration = () => {
    const isSurvey = $("chart-source-kind").value === "survey";
    const requiresAnswer = isSurvey && chartQuestionDetail && chartQuestionDetail.type !== "numeric";
    const hasSource = isSurvey ? Boolean(selectedChartSource()) : selectedChartStates().length > 0;
    const valid = Boolean(scope && hasSource && $("chart-title").value.trim()) && (!requiresAnswer || selectedChartAnswers().length > 0);
    $("create-chart").disabled = !valid;
  };
  const updateChartTitle = () => {
    const isSurvey = $("chart-source-kind").value === "survey";
    const source = selectedChartSource();
    const selectedStates = selectedChartStates();
    if (isSurvey && !source) return;
    if (!isSurvey && !selectedStates.length) return;
    const label = isSurvey ? source.question_text : selectedStates.map((state) => state.label).join(" vs ");
    const dimension = $("chart-dimension").value === "date" ? "por fecha" : "por región";
    $("chart-title").value = `${label} ${dimension}`;
    validateChartConfiguration();
  };
  const visibleChartStateIndexes = () => {
    const term = $("chart-state-search").value.trim().toLowerCase();
    return states.map((state, index) => ({ state, index }))
      .filter(({ state }) => !term || `${state.group} ${state.label}`.toLowerCase().includes(term))
      .map(({ index }) => index);
  };
  const renderChartStateOptions = () => {
    const container = $("chart-state-options");
    container.replaceChildren();
    const visibleIndexes = visibleChartStateIndexes();
    visibleIndexes.forEach((index) => {
      const state = states[index];
      const label = document.createElement("label"); label.className = "answer-option chart-state-option";
      const input = document.createElement("input"); input.type = "checkbox"; input.value = String(index); input.checked = selectedChartStateIndexes.has(index);
      input.addEventListener("change", () => {
        if (input.checked && selectedChartStateIndexes.size >= 8) {
          input.checked = false;
          showToast("Puedes comparar hasta ocho KPI en un mismo gráfico.", "error");
          return;
        }
        if (input.checked) selectedChartStateIndexes.add(index); else selectedChartStateIndexes.delete(index);
        $("chart-state-count").textContent = `${selectedChartStateIndexes.size} seleccionado${selectedChartStateIndexes.size === 1 ? "" : "s"}`;
        updateChartTitle(); validateChartConfiguration();
      });
      const copy = document.createElement("span");
      const name = document.createElement("strong"); name.textContent = state.label;
      const detail = document.createElement("small"); detail.textContent = `${state.group} · ${Number(state.locales || 0).toLocaleString("es-CL")} locales`;
      copy.append(name, detail); label.append(input, copy); container.appendChild(label);
    });
    if (!visibleIndexes.length) {
      const empty = document.createElement("p"); empty.className = "answer-option-empty"; empty.textContent = "No hay KPI que coincidan con la búsqueda."; container.appendChild(empty);
    }
    $("chart-state-count").textContent = `${selectedChartStateIndexes.size} seleccionado${selectedChartStateIndexes.size === 1 ? "" : "s"}`;
    const allVisibleSelected = visibleIndexes.length > 0 && visibleIndexes.every((index) => selectedChartStateIndexes.has(index));
    $("chart-select-all").textContent = allVisibleSelected ? "Quitar visibles" : "Seleccionar visibles";
  };
  const populateChartSourceItems = () => {
    const select = $("chart-source-item");
    select.replaceChildren();
    chartQuestionDetail = null;
    $("chart-answer-fieldset").hidden = true;
    $("chart-answer-options").replaceChildren();
    selectedChartStateIndexes = new Set();
    $("chart-state-search").value = "";
    $("chart-metric").replaceChildren(new Option("Locales distintos", "distinct_local"));
    const isSurvey = $("chart-source-kind").value === "survey";
    $("chart-source-item-field").hidden = !isSurvey;
    $("chart-state-fieldset").hidden = isSurvey;
    if (isSurvey) {
      surveyQuestions.forEach((question, index) => select.add(new Option(question.question_text, String(index))));
    } else {
      const visitedIndex = states.findIndex((state) => state.field === "estado_visita" && state.value === "VISITADO");
      if (visitedIndex >= 0) selectedChartStateIndexes.add(visitedIndex);
      renderChartStateOptions();
    }
    select.disabled = isSurvey && select.options.length === 0;
    updateChartTitle();
  };
  const loadChartQuestionOptions = async () => {
    chartQuestionDetail = null;
    $("chart-answer-fieldset").hidden = true;
    $("chart-answer-options").replaceChildren();
    const question = selectedChartSource();
    if (!question || !scope || $("chart-source-kind").value !== "survey") { validateChartConfiguration(); return; }
    $("chart-feedback").textContent = "Cargando respuestas de la pregunta...";
    $("create-chart").disabled = true;
    try {
      const query = new URLSearchParams({
        action: "question_options",
        id_division: String(scope.id_division),
        id_subdivision: String(scope.id_subdivision),
        id_formulario: String(scope.id_formulario),
        date_from: scope.date_from,
        question_text: question.question_text,
      });
      chartQuestionDetail = await apiRequest(`${config.api}?${query.toString()}`);
      const isNumeric = chartQuestionDetail.type === "numeric";
      $("chart-metric").replaceChildren(
        new Option("Locales distintos", "distinct_local"),
        ...(isNumeric ? [new Option("Promedio", "average"), new Option("Suma", "sum")] : [])
      );
      $("chart-answer-fieldset").hidden = isNumeric;
      if (!isNumeric) chartQuestionDetail.options.forEach((option) => {
        const label = document.createElement("label");
        label.className = "answer-option";
        const input = document.createElement("input"); input.type = "checkbox"; input.value = option.label;
        input.addEventListener("change", validateChartConfiguration);
        const copy = document.createElement("span");
        const name = document.createElement("strong"); name.textContent = option.label;
        const detail = document.createElement("small"); detail.textContent = `${Number(option.locales || 0).toLocaleString("es-CL")} locales`;
        copy.append(name, detail); label.append(input, copy); $("chart-answer-options").appendChild(label);
      });
      $("chart-feedback").textContent = "";
    } catch (error) {
      $("chart-feedback").textContent = error.message;
    }
    validateChartConfiguration();
  };
  const closeChartModal = () => {
    chartModal.classList.remove("show");
    window.setTimeout(() => { chartModal.hidden = true; }, 180);
  };
  const openChartModal = () => {
    if (!scope) { showToast("Selecciona primero el alcance del informe.", "error"); return; }
    $("chart-source-kind").value = "state";
    $("chart-dimension").value = "region";
    populateChartSourceItems();
    document.querySelector('input[name="chart-type"][value="donut"]').checked = true;
    $("chart-feedback").textContent = "";
    validateChartConfiguration();
    chartModal.hidden = false;
    requestAnimationFrame(() => chartModal.classList.add("show"));
  };
  $("add-chart").addEventListener("click", openChartModal);
  $("cancel-chart").addEventListener("click", closeChartModal);
  $("chart-title").addEventListener("input", validateChartConfiguration);
  $("chart-source-kind").addEventListener("change", () => { populateChartSourceItems(); if ($("chart-source-kind").value === "survey") loadChartQuestionOptions(); });
  $("chart-source-item").addEventListener("change", () => { updateChartTitle(); if ($("chart-source-kind").value === "survey") loadChartQuestionOptions(); });
  $("chart-dimension").addEventListener("change", updateChartTitle);
  $("chart-state-search").addEventListener("input", renderChartStateOptions);
  $("chart-select-all").addEventListener("click", () => {
    const visibleIndexes = visibleChartStateIndexes();
    const allSelected = visibleIndexes.length > 0 && visibleIndexes.every((index) => selectedChartStateIndexes.has(index));
    if (allSelected) visibleIndexes.forEach((index) => selectedChartStateIndexes.delete(index));
    else visibleIndexes.forEach((index) => { if (selectedChartStateIndexes.size < 8) selectedChartStateIndexes.add(index); });
    renderChartStateOptions(); updateChartTitle(); validateChartConfiguration();
  });
  chartModal.addEventListener("click", (event) => { if (event.target === chartModal) closeChartModal(); });
  $("create-chart").addEventListener("click", async () => {
    const sourceKind = $("chart-source-kind").value;
    const sourceItem = selectedChartSource();
    const selectedStates = selectedChartStates();
    if (!scope || (sourceKind === "survey" && !sourceItem) || (sourceKind === "state" && !selectedStates.length)) return;
    const chartKind = document.querySelector('input[name="chart-type"]:checked')?.value || "donut";
    const dimension = $("chart-dimension").value;
    const metric = $("chart-metric").value;
    const dateFilter = elements.find((item) => item.type === "date_filter");
    const effectiveDateFrom = dateFilter?.dateFrom && dateFilter.dateFrom >= scope.date_from ? dateFilter.dateFrom : scope.date_from;
    const form = new FormData();
    form.append("action", "calculate_chart"); form.append("csrf_token", config.csrf);
    form.append("id_division", scope.id_division); form.append("id_subdivision", scope.id_subdivision); form.append("id_formulario", scope.id_formulario);
    form.append("date_from", effectiveDateFrom); form.append("date_to", dateFilter?.dateTo || "");
    form.append("source_kind", sourceKind); form.append("dimension", dimension); form.append("metric", metric);
    if (sourceKind === "state") {
      selectedStates.forEach((state) => { form.append("state_fields[]", state.field); form.append("state_values[]", state.value); });
    } else {
      form.append("question_text", sourceItem.question_text);
      selectedChartAnswers().forEach((answer) => form.append("selected_answers[]", answer));
    }
    const button = $("create-chart");
    button.disabled = true; button.firstChild.textContent = "Calculando... ";
    $("chart-feedback").textContent = "Consultando locales y agrupando resultados...";
    try {
      const data = await apiRequest(config.api, { method: "POST", body: form });
      const hasData = data.series?.length || data.datasets?.some((dataset) => dataset.series?.length);
      if (!hasData) throw new Error("No se encontraron datos para el indicador y agrupación seleccionados.");
      const series = data.series.map((entry, index) => ({ ...entry, color: chartPalette[index % chartPalette.length] }));
      const datasets = (data.datasets || []).map((dataset, index) => ({ ...dataset, color: chartPalette[index % chartPalette.length] }));
      createElement({
        type: "chart", chartKind, series, datasets,
        x: 100, y: 650 + (elements.length % 4) * 25, width: 680, height: 390,
        content: $("chart-title").value.trim() || data.title || "Análisis KPI",
        fontSize: 15, fontWeight: "700", color: "#17243b", background: "#ffffff", radius: 14,
        chartColors: chartPalette.slice(0, 4), legendPosition: "right",
        source: {
          type: "direct_kpi_chart", chart_kind: chartKind, source_kind: sourceKind, dimension, metric,
          states: sourceKind === "state" ? selectedStates.map((state) => ({ field: state.field, value: state.value })) : undefined,
          question_text: sourceKind === "survey" ? sourceItem.question_text : undefined,
          selected_answers: sourceKind === "survey" ? selectedChartAnswers() : undefined,
          report_date_from: effectiveDateFrom, report_date_to: dateFilter?.dateTo || "", unit: data.unit,
        },
      });
      closeChartModal();
      showToast(`Gráfico agregado con ${sourceKind === "state" ? selectedStates.length : 1} serie(s).`);
    } catch (error) {
      $("chart-feedback").textContent = error.message;
    } finally {
      button.innerHTML = 'Agregar gráfico <span>→</span>';
      validateChartConfiguration();
    }
  });

  const shapeMenuButton = $("shape-menu-button");
  const shapeMenu = $("shape-menu");
  const closeShapeMenu = () => { shapeMenu.hidden = true; shapeMenuButton.setAttribute("aria-expanded", "false"); };
  shapeMenuButton.addEventListener("click", () => {
    const willOpen = shapeMenu.hidden;
    shapeMenu.hidden = !willOpen;
    shapeMenuButton.setAttribute("aria-expanded", String(willOpen));
  });
  document.querySelectorAll("[data-shape]").forEach((button) => {
    button.addEventListener("click", () => {
      const kind = button.dataset.shape;
      const names = { rectangle: "Rectángulo", rounded: "Rectángulo redondeado", ellipse: "Círculo / elipse", line: "Línea", arrow: "Flecha", triangle: "Triángulo", diamond: "Rombo" };
      const isLinear = kind === "line" || kind === "arrow";
      const isEllipse = kind === "ellipse";
      createElement({
        type: "shape",
        shapeKind: kind,
        x: 110,
        y: 130,
        width: isLinear ? 330 : isEllipse ? 190 : 250,
        height: isLinear ? 64 : isEllipse ? 190 : 160,
        content: names[kind] || "Forma",
        shapeFill: "#4f72d8",
        shapeStroke: "#274b9f",
        shapeStrokeWidth: 3,
        shapeStrokeStyle: "solid",
        rotation: 0,
        background: "transparent",
        radius: 0,
      });
      closeShapeMenu();
    });
  });
  document.addEventListener("click", (event) => { if (!event.target.closest(".shape-picker")) closeShapeMenu(); });
  document.addEventListener("keydown", (event) => { if (event.key === "Escape") closeShapeMenu(); });

  $("add-date-filter").addEventListener("click", () => createElement({
    type: "date_filter",
    x: 90,
    y: 110,
    width: 410,
    height: 105,
    content: "Rango de fecha de visita",
    dateFrom: scope?.date_from || "",
    dateTo: "",
    fontSize: 13,
    fontWeight: "700",
    color: "#17243b",
    background: "#ffffff",
    radius: 12,
    source: {
      type: "dashboard_filter",
      field: "visit_date",
      applies_to: "all_kpis",
      source_fields: { states: "formularioQuestion.fechaVisita", surveys: "form_question_responses.created_at" },
    },
  }));

  $("image-upload").addEventListener("change", async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    const form = new FormData();
    form.append("action", "upload"); form.append("csrf_token", config.csrf); form.append("image", file);
    try {
      showToast("Subiendo imagen...", "neutral");
      const data = await apiRequest(config.api, { method: "POST", body: form });
      createElement({ type: "image", x: 100, y: 120, width: 360, height: 240, src: data.url, content: data.name, objectFit: "contain", radius: 8 });
      showToast("Imagen agregada al lienzo.");
    } catch (error) { showToast(error.message, "error"); }
    event.target.value = "";
  });

  $("delete-element").addEventListener("click", removeSelectedElement);

  document.addEventListener("keydown", (event) => {
    if (isEditableTarget(event.target)) return;
    if (editorView.hidden || !scope) return;

    const modifier = event.ctrlKey || event.metaKey;
    const key = String(event.key || "").toLowerCase();

    if (modifier && key === "c") {
      if (copySelectedElement()) event.preventDefault();
      return;
    }

    if (modifier && key === "v") {
      if (pasteCopiedElement()) event.preventDefault();
      return;
    }

    if (event.key === "Delete" || event.code === "Delete") {
      if (removeSelectedElement()) {
        event.preventDefault();
        showToast("Elemento eliminado.", "neutral");
      }
    }
  });

  canvas.addEventListener("pointerdown", (event) => {
    const node = event.target.closest(".canvas-element");
    if (!node) { selectElement(null); return; }
    event.preventDefault();
    selectElement(node.dataset.elementId);
    const item = selectedElement();
    if (!item) return;
    const resize = event.target.dataset.resize || "";
    const start = { clientX: event.clientX, clientY: event.clientY, x: item.x, y: item.y, width: item.width, height: item.height };
    const move = (moveEvent) => {
      const dx = (moveEvent.clientX - start.clientX) / zoom;
      const dy = (moveEvent.clientY - start.clientY) / zoom;
      if (resize) {
        let x = start.x, y = start.y, width = start.width, height = start.height;
        if (resize.includes("e")) width = Math.max(30, start.width + dx);
        if (resize.includes("s")) height = Math.max(30, start.height + dy);
        if (resize.includes("w")) { width = Math.max(30, start.width - dx); x = start.x + (start.width - width); }
        if (resize.includes("n")) { height = Math.max(30, start.height - dy); y = start.y + (start.height - height); }
        Object.assign(item, { x: Math.max(0, x), y: Math.max(0, y), width, height });
      } else {
        item.x = Math.max(0, start.x + dx); item.y = Math.max(0, start.y + dy);
      }
      renderElements(); syncProperties();
    };
    const up = () => { window.removeEventListener("pointermove", move); window.removeEventListener("pointerup", up); saveDraft(); };
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", up, { once: true });
  });

  const propertyMap = {
    "prop-content": ["content", String], "prop-x": ["x", Number], "prop-y": ["y", Number], "prop-width": ["width", Number], "prop-height": ["height", Number],
    "prop-date-from": ["dateFrom", String], "prop-date-to": ["dateTo", String],
    "prop-chart-kind": ["chartKind", String],
    "prop-legend-position": ["legendPosition", String],
    "prop-shape-fill": ["shapeFill", String], "prop-shape-stroke": ["shapeStroke", String], "prop-shape-stroke-width": ["shapeStrokeWidth", Number], "prop-shape-stroke-style": ["shapeStrokeStyle", String], "prop-shape-rotation": ["rotation", Number],
    "prop-font": ["fontFamily", String], "prop-font-size": ["fontSize", Number], "prop-font-weight": ["fontWeight", String], "prop-color": ["color", String], "prop-background": ["background", String],
    "prop-align": ["align", String], "prop-object-fit": ["objectFit", String], "prop-radius": ["radius", Number],
  };
  Object.entries(propertyMap).forEach(([id, [key, cast]]) => {
    $(id).addEventListener("input", (event) => {
      updateSelected({ [key]: cast(event.target.value) });
      if (id === "prop-radius") $("radius-output").textContent = `${event.target.value} px`;
      if (id === "prop-shape-rotation") $("shape-rotation-output").textContent = `${event.target.value}°`;
    });
  });
  ["prop-chart-color-1", "prop-chart-color-2", "prop-chart-color-3", "prop-chart-color-4"].forEach((id, index) => {
    $(id).addEventListener("input", (event) => {
      const item = selectedElement();
      if (!item || item.type !== "chart") return;
      const colors = Array.isArray(item.chartColors) ? [...item.chartColors] : chartPalette.slice(0, 4);
      colors[index] = event.target.value;
      updateSelected({ chartColors: colors });
    });
  });

  $("layer-front").addEventListener("click", () => updateSelected({ z: ++zIndex }));
  $("layer-back").addEventListener("click", () => updateSelected({ z: 1 }));

  const updateCanvasSize = () => {
    canvas.style.width = `${Math.max(640, Number($("canvas-width").value) || 1200)}px`;
    canvas.style.height = `${Math.max(480, Number($("canvas-height").value) || 780)}px`;
    saveDraft();
  };
  $("canvas-width").addEventListener("change", updateCanvasSize);
  $("canvas-height").addEventListener("change", updateCanvasSize);
  $("canvas-zoom").addEventListener("input", (event) => {
    zoom = Number(event.target.value) / 100;
    canvas.style.transform = `scale(${zoom})`;
    $("zoom-output").textContent = `${event.target.value}%`;
  });

  $("download-design").addEventListener("click", () => {
    const design = { version: 1, created_at: new Date().toISOString(), template: selectedTemplate, scope, canvas: { width: Number($("canvas-width").value), height: Number($("canvas-height").value) }, elements };
    const blob = new Blob([JSON.stringify(design, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url; link.download = `perfect-store-${selectedTemplate.id}-${scope?.id_division || "draft"}.json`;
    document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
    showToast("Diseño descargado como JSON.");
  });
})();
