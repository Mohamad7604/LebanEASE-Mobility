document.addEventListener("DOMContentLoaded", () => {
  let selectedSeatEl = null;

  const selectedText = document.getElementById("selectedSeat");
  const seatInput = document.getElementById("seat_number");
  const confirmBtn = document.getElementById("confirmBtn");
  const form = document.getElementById("reservationForm");

  // If the page is in "trip completed" mode, these might not exist
  if (!seatInput || !confirmBtn || !form) return;

  const seats = document.querySelectorAll(".seat.available[data-seat]");
  if (!seats.length) return;

  seats.forEach((seat) => {
    seat.addEventListener("click", () => {
      // unselect old
      if (selectedSeatEl) selectedSeatEl.classList.remove("selected");

      // select new
      seat.classList.add("selected");
      selectedSeatEl = seat;

      const seatNum = seat.dataset.seat;
      seatInput.value = seatNum;

      // update text
      const strong = selectedText ? selectedText.querySelector("strong") : null;
      if (strong) strong.textContent = seatNum;
      else if (selectedText) selectedText.textContent = `Selected Seat: ${seatNum}`;

      // enable button
      confirmBtn.disabled = false;
    });
  });

  form.addEventListener("submit", (e) => {
    if (!seatInput.value) {
      e.preventDefault();
      alert("Please select a seat first.");
    }
  });
});
