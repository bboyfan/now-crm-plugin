/**
 * Contract between the campaign workspace and its embedded block editor frame.
 *
 * Both sides are same-origin admin pages, so the workspace reads this object
 * from the iframe's contentWindow. No postMessage channel is used.
 */

export type EmailDesign = {
	body: string;
	content: string;
	heading: string;
	text: string;
	link: string;
};

export type EditorBridgeState = { ready: boolean; dirty: boolean; revision: string; design: EmailDesign };

export type EditorBridge = {
	subscribe: ( listener: ( state: EditorBridgeState ) => void ) => () => void;
	blocks: () => string | null;
	design: () => EmailDesign;
	isReady: () => boolean;
	isDirty: () => boolean;
	revision: () => string;
	campaignId: () => number;
	markSaved: ( revision: string, blocks: string, design: EmailDesign ) => void;
	subscribeTemplatePicker: ( listener: () => void ) => () => void;
	requestTemplatePicker: () => void;
	/**
	 * Sequence frames start blank and are seeded per step. Calls are ignored
	 * (ready: false) until the editor has loaded the working post.
	 */
	replaceBlocks?: ( serialized: string ) => { ready: boolean; blocks: string | null };
};

declare global {
	interface Window {
		nowcrmEditorBridge?: EditorBridge;
	}
}
