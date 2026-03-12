const scene = document.getElementById("scene");
const carousel = document.getElementById("carousel");

let rotationY = 0;
let autoSpeed = 0.05;
let velocity = autoSpeed;
let isDragging = false;
let startX = 0;
let lastX = 0;
let dragVelocity = 0;
let resumeTimeout = null;
let moved = false;

function getClientX(e) {
  return e.touches ? e.touches[0].clientX : e.clientX;
}

function pauseAutoResume() {
  clearTimeout(resumeTimeout);
  resumeTimeout = setTimeout(() => {
    velocity = autoSpeed;
  }, 1800);
}

function startDrag(e) {
  isDragging = true;
  moved = false;
  scene.classList.add("dragging");

  startX = getClientX(e);
  lastX = startX;
  dragVelocity = 0;
  clearTimeout(resumeTimeout);
}

function onDrag(e) {
  if (!isDragging) return;

  const clientX = getClientX(e);
  const deltaX = clientX - lastX;

  if (Math.abs(clientX - startX) > 6) {
    moved = true;
  }

  rotationY += deltaX * 0.35;
  dragVelocity = deltaX * 0.08;
  lastX = clientX;
}

function endDrag() {
  if (!isDragging) return;

  isDragging = false;
  scene.classList.remove("dragging");

  velocity = dragVelocity;
  pauseAutoResume();
}

function animate() {
  if (!isDragging) {
    rotationY += velocity;
    velocity *= 0.97;

    if (Math.abs(velocity) < Math.abs(autoSpeed)) {
      velocity = autoSpeed;
    }
  }

  carousel.style.transform = `rotateY(${rotationY}deg)`;
  requestAnimationFrame(animate);
}

scene.addEventListener("mousedown", startDrag);
window.addEventListener("mousemove", onDrag);
window.addEventListener("mouseup", endDrag);

scene.addEventListener("touchstart", startDrag, { passive: true });
window.addEventListener("touchmove", onDrag, { passive: true });
window.addEventListener("touchend", endDrag);

document.addEventListener("click", function(e) {
  const link = e.target.closest(".card-link");
  if (!link) return;

  if (moved) {
    e.preventDefault();
    e.stopPropagation();
    moved = false;
  }
});

animate();