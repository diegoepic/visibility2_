class AccordionSlider {
	constructor() {
		this.slides = document.querySelectorAll(".slide");
		this.prevBtn = document.querySelector(".nav-prev");
		this.nextBtn = document.querySelector(".nav-next");
		this.currentIndex = -1;

		this.init();
	}

	init() {
		this.slides.forEach((slide, index) => {
			slide.addEventListener("click", (e) => this.handleSlideClick(e, index));
		});

		if (this.prevBtn) this.prevBtn.addEventListener("click", () => this.previousSlide());
		if (this.nextBtn) this.nextBtn.addEventListener("click", () => this.nextSlide());

		document.addEventListener("keydown", (e) => {
			if (e.key === "ArrowLeft") this.previousSlide();
			if (e.key === "ArrowRight") this.nextSlide();
		});
	}

	handleSlideClick(e, index) {
		// Si el slide ya estaba activo → abrir el link
		if (this.currentIndex === index && this.slides[index].classList.contains("active")) {
			const url = this.slides[index].dataset.url;
			if (url) {
				window.open(url, "_blank");
			}
			return; // Evitar que se desactive
		}

		// Si no estaba activo → activar y desactivar los demás
		this.setActiveSlide(index);
	}

	setActiveSlide(index) {
		this.slides.forEach((slide) => slide.classList.remove("active"));
		this.slides[index].classList.add("active");
		this.currentIndex = index;
	}

	nextSlide() {
		if (!this.slides.length) return;
		const nextIndex =
			this.currentIndex === -1 ? 0 : (this.currentIndex + 1) % this.slides.length;
		this.setActiveSlide(nextIndex);
	}

	previousSlide() {
		if (!this.slides.length) return;
		const prevIndex =
			this.currentIndex === -1
				? this.slides.length - 1
				: (this.currentIndex - 1 + this.slides.length) % this.slides.length;
		this.setActiveSlide(prevIndex);
	}
}

document.addEventListener("DOMContentLoaded", () => {
	new AccordionSlider();
});

