<div class="farm-reels" wire:ignore>
    <div class="farm-reels-heading">
        <div>
            <p class="eyebrow">Life at Agung Farm</p>
            <h3>Little moments. Fresh from the farm.</h3>
        </div>
        <a href="https://www.instagram.com/agungfarmandgarden/" target="_blank" rel="noopener noreferrer" class="farm-text-link">@agungfarmandgarden <x-heroicon-o-arrow-up-right class="h-4 w-4" aria-hidden="true" /></a>
    </div>
    <x-public.product-rail id="farm-reels" label="Instagram Reels">
        @foreach (['Ddyd1XkB5OG', 'DcQgUxBhj60', 'Db8Kmi_BYAw', 'DbxsAnQh85K', 'DbA68Q5BbNu'] as $reel)
            <article class="farm-reel">
                <div class="farm-reel-preview">
                    <iframe
                        src="https://www.instagram.com/reel/{{ $reel }}/embed/"
                        title="Agung Farm and Garden Instagram Reel {{ $loop->iteration }}"
                        loading="lazy"
                        scrolling="no"
                        allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                        allowfullscreen
                    ></iframe>
                </div>
                <div class="farm-reel-caption">
                    <span aria-hidden="true">0{{ $loop->iteration }}</span>
                    <a href="https://www.instagram.com/reel/{{ $reel }}/" target="_blank" rel="noopener noreferrer" class="farm-text-link">Watch the story <x-heroicon-o-arrow-up-right class="h-4 w-4" aria-hidden="true" /></a>
                </div>
            </article>
        @endforeach
    </x-public.product-rail>
</div>
