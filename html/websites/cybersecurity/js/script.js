document.addEventListener("DOMContentLoaded", () => {


  const contactForm = document.getElementById("contactForm");
  if (contactForm) {
    const statusTxt = contactForm.querySelector(".button-area span");
    contactForm.addEventListener("submit", (e) => {
      e.preventDefault(); 
      statusTxt.style.color = "#0D6EFD";
      statusTxt.style.display = "block";
      statusTxt.innerText = "Sending your message...";
      contactForm.classList.add("disabled");

      let xhr = new XMLHttpRequest();
      xhr.open("POST", contactForm.getAttribute("action"), true);
      xhr.onload = () => {
        if (xhr.readyState === 4 && xhr.status === 200) {
          let response = xhr.response;
          
		  
          if (
            response.indexOf("required") !== -1 ||
            response.indexOf("Enter a valid email") !== -1 ||
            response.indexOf("failed") !== -1
          ) {
            statusTxt.style.color = "red";
          } else {
            contactForm.reset();
            setTimeout(() => {
              statusTxt.style.display = "none";
            }, 3000);
          }
          statusTxt.innerText = response;
          contactForm.classList.remove("disabled");
        }
      };
      let formData = new FormData(contactForm);
      xhr.send(formData);
    });
  }


  const joinForm = document.getElementById("join-form");
  if (joinForm) {
    const statusSpan = joinForm.querySelector(".status-text");
    joinForm.addEventListener("submit", (e) => {
      e.preventDefault(); 
      statusSpan.style.color = "#0D6EFD";
      statusSpan.style.display = "block";
      statusSpan.innerText = "Sending your information...";
      joinForm.classList.add("disabled");

      let xhr = new XMLHttpRequest();
      xhr.open("POST", joinForm.getAttribute("action"), true);
      xhr.onload = () => {
        if (xhr.readyState === 4 && xhr.status === 200) {
          let response = xhr.response;
          
		  
          if (
            response.indexOf("required") !== -1 ||
            response.indexOf("Enter a valid email") !== -1 ||
            response.indexOf("failed") !== -1
          ) {
            statusSpan.style.color = "red";
          } else {
            joinForm.reset();
            setTimeout(() => {
              statusSpan.style.display = "none";
            }, 3000);
          }
          statusSpan.innerText = response;
          joinForm.classList.remove("disabled");
        }
      };

      let formData = new FormData(joinForm);
      xhr.send(formData);
    });
  }
});
