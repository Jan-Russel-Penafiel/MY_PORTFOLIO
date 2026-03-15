
const fs = require('fs');
const lines = fs.readFileSync('c:/xampp/htdocs/doc/index1.html', 'utf8').split('\n');

// Keep lines 0-1100 (1-indexed 1-1101): first head + first body html + first body scripts
const firstPart = lines.slice(0, 1101).join('\n');

const uniqueContent = `
    <!-- Hidden file input for gallery upload -->
    <input type="file" id="galleryFileInput" accept="image/*" multiple style="display:none">

    <script>
      // ===== Photo Gallery Upload =====
      function openGalleryUpload() {
        var fi = document.getElementById('galleryFileInput');
        if (fi) fi.click();
      }

      var _galleryInput = document.getElementById('galleryFileInput');
      if (_galleryInput) {
        _galleryInput.addEventListener('change', function(e) {
          const files = Array.from(e.target.files || []);
          if (!files.length) return;
          uploadGalleryImages(files);
          e.target.value = '';
        });
      }

      function uploadGalleryImages(files) {
        const formData = new FormData();
        formData.append('action', 'gallery_upload');
        files.forEach(function(file) {
          formData.append('images[]', file);
        });
        showToggleNotification('<i class="fas fa-spinner fa-spin me-2"></i>Uploading to gallery...');
        fetch('upload.php', {
          method: 'POST',
          body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
          if (data.success) {
            showToggleNotification('<i class="fas fa-check-circle me-2"></i>Gallery updated! Reloading...');
            setTimeout(function() { location.reload(); }, 1200);
          } else {
            showToggleNotification('<i class="fas fa-exclamation-circle me-2"></i>' + (data.message || 'Upload failed'));
          }
        })
        .catch(function(err) {
          showToggleNotification('<i class="fas fa-exclamation-circle me-2"></i>Upload error: ' + err.message);
        });
      }

      document.addEventListener("click", function (e) {
        const link = e.target.closest(".project-image-link");
        if (!link) return;
        e.preventDefault();
        let images = [];
        try {
          images = JSON.parse(link.getAttribute("data-images") || "[]");
        } catch (err) {
          images = [];
        }
        if (!images.length && link.href) {
          images = [link.href];
        }
        const card = link.closest(".project-card");
        currentProjectId = card ? card.getAttribute("data-project-id") : null;
        openGalleryModal(images, 0);
      });

      document.addEventListener("DOMContentLoaded", prepareGalleryLinks);
      prepareGalleryLinks();

      // Close modal when clicking outside
      var _uploadModal = document.getElementById("uploadModal");
      if (_uploadModal) {
        _uploadModal.addEventListener("click", function (e) {
          if (e.target === this) {
            closeUploadModal();
          }
        });
      }

      // Profile Picture Upload Functions
      let selectedProfileFile = null;

      function handleProfileFileSelect(event) {
        const file = event.target.files[0];
        if (!file) { return; }
        selectedProfileFile = file;
        const reader = new FileReader();
        reader.onload = function (e) {
          const preview = document.getElementById("profileUploadPreview");
          if (preview) {
            preview.src = e.target.result;
            preview.classList.add("show");
          }
        };
        reader.readAsDataURL(file);
        const uploadBtn = document.getElementById("profileUploadBtn");
        if (uploadBtn) { uploadBtn.style.display = "inline-block"; }
        const profileUploadStatus = document.getElementById("profileUploadStatus");
        if (profileUploadStatus) {
          profileUploadStatus.innerHTML = "Profile image ready to upload.";
        }
        uploadProfilePictureDirectly(file);
      }

      function uploadProfilePicture() {
        if (!selectedProfileFile) { return; }
        uploadProfilePictureDirectly(selectedProfileFile);
      }

      function resetProfileUploadForm() {
        const profileInput = document.getElementById("profileFileInput");
        if (profileInput) { profileInput.value = ""; }
        const profilePreview = document.getElementById("profileUploadPreview");
        if (profilePreview) {
          profilePreview.classList.remove("show");
          profilePreview.removeAttribute("src");
        }
        const profileUploadBtn = document.getElementById("profileUploadBtn");
        if (profileUploadBtn) { profileUploadBtn.style.display = "none"; }
        const profileUploadStatus = document.getElementById("profileUploadStatus");
        if (profileUploadStatus) { profileUploadStatus.innerHTML = ""; }
        selectedProfileFile = null;
      }

      function uploadProfilePictureDirectly(file) {
        if (!file.type.match("image.*")) {
          alert("Please select an image file");
          return;
        }
        if (file.size > 5 * 1024 * 1024) {
          alert("File size must be less than 5MB");
          return;
        }
        const formData = new FormData();
        formData.append("image", file);
        formData.append("action", "update_profile");
        showToggleNotification("Uploading profile picture...");
        fetch("upload.php", { method: "POST", body: formData })
          .then((response) => response.json())
          .then((data) => {
            if (data.success) {
              showToggleNotification("Profile picture updated! Reloading...");
              const profilePicture = document.getElementById("profilePicture");
              if (profilePicture) { profilePicture.src = data.data.path; }
              const heroProfilePic = document.getElementById("heroProfilePicture");
              if (heroProfilePic) { heroProfilePic.src = data.data.path; }
              setTimeout(() => { window.location.reload(); }, 1000);
            } else {
              showToggleNotification(data.message);
            }
          })
          .catch((error) => {
            showToggleNotification("Upload failed: " + error.message);
          });
      }

      // Close modals when clicking outside
      ["mainMenuModal"].forEach((modalId) => {
        const modal = document.getElementById(modalId);
        if (modal) {
          modal.addEventListener("click", function (e) {
            if (e.target === this) { this.classList.remove("active"); }
          });
        }
      });
    </script>

    <!-- Mobile Floating Theme Toggle (mobile only) -->
    <button id="mobileThemeToggle" onclick="toggleTheme()" title="Switch theme">
      <i class="fas fa-moon icon-moon"></i>
      <i class="fas fa-sun icon-sun"></i>
    </button>

    <!-- Performance & UI Optimizer -->
    <script src="optimize.js" defer></script>

    <!-- Profile photo: dark = RUSSELS1.png, light = RUSSELS.png -->
    <script>
      (function () {
        function updateProfilePhoto(isLight) {
          var photo = document.getElementById("cv-profile-photo");
          if (photo) photo.src = isLight ? "./RUSSELS.png" : "./RUSSELS1.png";
        }
        updateProfilePhoto(document.body.classList.contains("light-mode"));
        var _origToggle = window.toggleTheme;
        window.toggleTheme = function () {
          _origToggle();
          updateProfilePhoto(document.body.classList.contains("light-mode"));
        };
      })();
    </script>
  </body>
</html>`;

const result = firstPart + '\n' + uniqueContent;
fs.writeFileSync('c:/xampp/htdocs/doc/index1.html', result, 'utf8');
console.log('Done. New line count:', result.split('\n').length);
