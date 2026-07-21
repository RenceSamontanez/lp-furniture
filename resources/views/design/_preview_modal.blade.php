{{-- ═══════════════════════════════════
     SHARED DESIGN PREVIEW MODAL
═══════════════════════════════════ --}}
<div class="cd-preview-modal-overlay" id="cd-preview-modal-overlay" onclick="closeDesignModalOnBackdrop(event)">
  <div class="cd-preview-modal">

    <div class="cd-preview-modal-header">
      <button type="button" class="cd-preview-modal-back" onclick="closeDesignModal()">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back
      </button>
      <span class="cd-preview-modal-title">Design Preview</span>
      <span style="width:70px;"></span>
    </div>

    <div class="cd-preview-modal-body">

      <div class="cd-preview-modal-img-wrap">
        <img src="" alt="Design preview" id="cd-modal-preview-img" class="cd-preview-modal-img"/>
      </div>

      {{-- Save button now sits in its OWN row right below the image,
           not overlapping it at all — same safe pattern as everywhere else. --}}
      <button type="button" class="cd-save-btn js-save-heart" id="cd-modal-heart-btn" style="margin-bottom:16px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="cd-modal-heart-icon">
          <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/>
        </svg>
        <span class="cd-save-btn-text">Save</span>
      </button>

      <div class="cd-preview-modal-info">
        <div class="cd-preview-modal-type" id="cd-modal-furniture-type">Custom Furniture</div>
        <p class="cd-preview-modal-desc" id="cd-modal-description">—</p>
        <span class="cd-preview-modal-status" id="cd-modal-status">pending</span>
      </div>

      <div class="cd-order-cta">
        <div class="cd-order-cta-text">
          <strong>Love this design?</strong>
          <span>Turn it into a real custom order — our team will reach out to finalize pricing and production.</span>
        </div>
        <form method="POST" id="cd-modal-order-form">
          @csrf
          <input type="hidden" name="suggestion_id" id="cd-modal-suggestion-id" value=""/>
          <button type="submit" class="cd-order-cta-btn">
            Order This Design
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
        </form>
      </div>

      <a href="#" id="cd-modal-view-all-link" class="cd-modal-view-all">
        View All 4 Concepts &amp; Regenerate →
      </a>

    </div>
  </div>
</div>

<script>
function openDesignModal(requestId, suggestionId, imageUrl, furnitureType, description, status, isFavorited) {
  document.getElementById('cd-modal-preview-img').src = imageUrl;
  document.getElementById('cd-modal-furniture-type').textContent = furnitureType || 'Custom Furniture';
  document.getElementById('cd-modal-description').textContent = description || '';
  document.getElementById('cd-modal-status').textContent = (status || '').replace('_', ' ');
  document.getElementById('cd-modal-status').className = 'cd-preview-modal-status status-' + status;
  document.getElementById('cd-modal-suggestion-id').value = suggestionId;
  document.getElementById('cd-modal-order-form').action = '/design/' + requestId + '/select';
  document.getElementById('cd-modal-view-all-link').href = '/design/' + requestId;

  const saveBtn = document.getElementById('cd-modal-heart-btn');
  saveBtn.dataset.suggestionId = suggestionId;
  saveBtn.classList.toggle('saved', !!isFavorited);
  document.getElementById('cd-modal-heart-icon').setAttribute('fill', isFavorited ? 'currentColor' : 'none');
  saveBtn.querySelector('.cd-save-btn-text').textContent = isFavorited ? 'Saved' : 'Save';

  document.getElementById('cd-preview-modal-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeDesignModal() {
  document.getElementById('cd-preview-modal-overlay').classList.remove('show');
  document.body.style.overflow = '';
}

function closeDesignModalOnBackdrop(e) {
  if (e.target.id === 'cd-preview-modal-overlay') closeDesignModal();
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeDesignModal();
});
</script>