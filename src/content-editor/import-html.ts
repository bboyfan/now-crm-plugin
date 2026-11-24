import { createBlock, rawHandler, type Block } from '@wordpress/blocks';

const CONTENT_SELECTOR = 'h1,h2,h3,h4,h5,h6,p,ul,ol,blockquote,figure,img,hr,a';

function isHidden( element: Element ): boolean {
	for ( let node: Element | null = element; node; node = node.parentElement ) {
		if ( node.hasAttribute( 'hidden' ) ) {
			return true;
		}

		const style = node.getAttribute( 'style' ) || '';

		if ( /display\s*:\s*none|mso-hide\s*:\s*all/i.test( style ) ) {
			return true;
		}
	}

	return false;
}

function looksLikeButton( element: Element ): boolean {
	const style = element.getAttribute( 'style' ) || '';
	const className = element.getAttribute( 'class' ) || '';

	return /display\s*:\s*block|background(?:-color)?\s*:/i.test( style ) || /button|btn|cta/i.test( className );
}

function hasSelectedAncestor( element: Element, root: Element ): boolean {
	for ( let parent = element.parentElement; parent && parent !== root; parent = parent.parentElement ) {
		// A linked image belongs to the image block, not to a button.
		const linkedImage = 'A' === parent.tagName && !! parent.querySelector( 'img,figure' );

		if ( ! linkedImage && parent.matches( CONTENT_SELECTOR ) ) {
			return true;
		}
	}

	return false;
}

function sanitizeDocument( doc: Document ): void {
	doc.querySelectorAll( 'script,style,meta,link,title,head,noscript,iframe,object,embed,form,input,button,textarea,select' ).forEach( ( node ) => node.remove() );

	doc.body.querySelectorAll( '*' ).forEach( ( element ) => {
		for ( const attribute of Array.from( element.attributes ) ) {
			if ( /^on/i.test( attribute.name ) || /javascript\s*:/i.test( attribute.value ) ) {
				element.removeAttribute( attribute.name );
			}
		}
	} );
}

function convertElement( element: Element ): Block[] {
	if ( 'A' === element.tagName ) {
		const href = element.getAttribute( 'href' ) || '';
		const text = ( element.textContent || '' ).trim();

		if ( '' === text ) {
			return [];
		}

		if ( looksLikeButton( element ) ) {
			return [
				createBlock( 'core/buttons', {}, [
					createBlock( 'core/button', { text, url: href } ),
				] ),
			];
		}

		return rawHandler( { HTML: `<p>${ element.outerHTML }</p>` } );
	}

	return rawHandler( { HTML: element.outerHTML } );
}

/**
 * Convert pasted email/article HTML into core blocks that remain editable in
 * Gutenberg.
 *
 * Table-based email scaffolding is intentionally not preserved: the converter
 * extracts its content (headings, paragraphs, lists, quotes, images, links and
 * button-like anchors) in document order. Pixel-perfect imports belong in the
 * Raw HTML editor instead.
 */
export function htmlToBlocks( html: string ): Block[] {
	const doc = new DOMParser().parseFromString( html, 'text/html' );
	sanitizeDocument( doc );

	const blocks: Block[] = [];
	const candidates = Array.from( doc.body.querySelectorAll( CONTENT_SELECTOR ) );

	for ( const element of candidates ) {
		if ( isHidden( element ) || hasSelectedAncestor( element, doc.body ) ) {
			continue;
		}

		if ( 'A' === element.tagName && element.querySelector( 'img,figure' ) ) {
			continue;
		}

		try {
			blocks.push( ...convertElement( element ) );
		} catch {
			// One malformed fragment must not abort the whole import.
		}
	}

	// Cells whose copy is plain text (no paragraph/image/table inside) would
	// otherwise disappear with the layout table.
	doc.body.querySelectorAll( 'td,th' ).forEach( ( cell ) => {
		const hasContent = !! cell.querySelector( 'h1,h2,h3,h4,h5,h6,p,ul,ol,blockquote,figure,img,hr,a,table' );

		if ( isHidden( cell ) || hasContent || '' === ( cell.textContent || '' ).trim() || hasSelectedAncestor( cell, doc.body ) ) {
			return;
		}

		try {
			blocks.push( ...rawHandler( { HTML: `<p>${ cell.innerHTML }</p>` } ) );
		} catch {
			// Ignore a cell that cannot be represented as a block.
		}
	} );

	return blocks;
}
