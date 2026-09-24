/**
 * Lumina Editor — Advanced Emoji Picker
 *
 * Features:
 *   - Categorized emojis (Smileys, Gestures, Animals, Food, Activities,
 *     Travel, Objects, Symbols, Flags)
 *   - Search filter (by name/keyword)
 *   - Recently-used bar (persisted to localStorage)
 *   - Skin tone selector for supported emojis (light/dark/medium variants)
 *   - Keyboard navigation (arrow keys, Enter, Esc)
 *
 * Replaces the basic grid modal with a much richer picker.
 * Exposed as: editor.openEmojiPicker()
 */

const STORAGE_KEY = 'lumina_emoji_recent';
const MAX_RECENT = 24;

// Emoji catalogue grouped by category. Each entry: { char, name, keywords }
const EMOJI_CATALOG = [
    {
        id: 'smileys', icon: '😀', label: { ru: 'Смайлы', en: 'Smileys' },
        items: [
            { c: '😀', n: 'happy', k: 'smile joy face' },
            { c: '😃', n: 'happy', k: 'smile joy face eyes' },
            { c: '😄', n: 'happy', k: 'smile joy face eyes' },
            { c: '😁', n: 'grin', k: 'smile face teeth' },
            { c: '😆', n: 'laughing', k: 'squint face laugh' },
            { c: '😅', n: 'sweat smile', k: 'face sweat' },
            { c: '😂', n: 'joy', k: 'face tears laugh cry' },
            { c: '🤣', n: 'rofl', k: 'rolling face laugh' },
            { c: '🥲', n: 'tear', k: 'face tear' },
            { c: '☺️', n: 'blush', k: 'smile face' },
            { c: '😊', n: 'blush', k: 'smile face eyes' },
            { c: '😇', n: 'innocent', k: 'halo face angel' },
            { c: '🙂', n: 'slight smile', k: 'face' },
            { c: '🙃', n: 'upside down', k: 'face' },
            { c: '😉', n: 'wink', k: 'face eye' },
            { c: '😌', n: 'relieved', k: 'face calm' },
            { c: '😍', n: 'heart eyes', k: 'face love' },
            { c: '🥰', n: 'hearts', k: 'face love' },
            { c: '😘', n: 'kiss', k: 'face love' },
            { c: '😋', n: 'yum', k: 'face tongue' },
            { c: '😛', n: 'tongue', k: 'face' },
            { c: '😜', n: 'wink tongue', k: 'face' },
            { c: '🤪', n: 'crazy', k: 'face' },
            { c: '😎', n: 'cool', k: 'face sunglasses' },
            { c: '🤩', n: 'star struck', k: 'face star' },
            { c: '🥳', n: 'party', k: 'face celebrate' },
            { c: '😏', n: 'smirk', k: 'face' },
            { c: '😒', n: 'unamused', k: 'face' },
            { c: '😞', n: 'disappointed', k: 'face sad' },
            { c: '😔', n: 'pensive', k: 'face sad' },
            { c: '😟', n: 'worried', k: 'face' },
            { c: '😢', n: 'cry', k: 'face tear' },
            { c: '😭', n: 'sob', k: 'face cry' },
            { c: '😤', n: 'triumph', k: 'face angry' },
            { c: '😠', n: 'angry', k: 'face' },
            { c: '😡', n: 'rage', k: 'face red' },
            { c: '🤬', n: 'cursing', k: 'face' },
            { c: '🤯', n: 'mind blown', k: 'face explode' },
            { c: '😳', n: 'flushed', k: 'face red' },
            { c: '😱', n: 'scream', k: 'face fear' },
            { c: '😨', n: 'fearful', k: 'face' },
            { c: '🤔', n: 'thinking', k: 'face' },
            { c: '🤗', n: 'hug', k: 'face' },
            { c: '🤫', n: 'shush', k: 'face quiet' },
            { c: '🤥', n: 'lying', k: 'face' },
            { c: '😶', n: 'no mouth', k: 'face' },
            { c: '😐', n: 'neutral', k: 'face' },
            { c: '😑', n: 'expressionless', k: 'face' },
            { c: '😬', n: 'grimacing', k: 'face' },
            { c: '🙄', n: 'rolling eyes', k: 'face' },
            { c: '😴', n: 'sleep', k: 'face bed' },
            { c: '🤤', n: 'drool', k: 'face' },
            { c: '😷', n: 'mask', k: 'face sick' },
            { c: '🤒', n: 'thermometer', k: 'face sick' },
            { c: '🤕', n: 'bandage', k: 'face hurt' },
            { c: '🤑', n: 'money', k: 'face' },
            { c: '🤠', n: 'cowboy', k: 'face hat' },
            { c: '😈', n: 'smiling devil', k: 'face horns' },
            { c: '👿', n: 'angry devil', k: 'face horns' },
            { c: '👻', n: 'ghost', k: 'spooky' },
            { c: '💀', n: 'skull', k: 'dead' },
            { c: '👽', n: 'alien', k: 'space' },
            { c: '🤖', n: 'robot', k: 'ai' }
        ]
    },
    {
        id: 'gestures', icon: '👋', label: { ru: 'Жесты', en: 'Gestures' },
        items: [
            { c: '👋', n: 'wave', k: 'hand hello bye' },
            { c: '🤚', n: 'raised back', k: 'hand stop' },
            { c: '✋', n: 'raised', k: 'hand stop' },
            { c: '🖖', n: 'vulcan', k: 'hand spock' },
            { c: '👌', n: 'ok', k: 'hand sign' },
            { c: '🤏', n: 'pinching', k: 'hand small' },
            { c: '✌️', n: 'peace', k: 'hand victory' },
            { c: '🤞', n: 'crossed', k: 'fingers luck' },
            { c: '🤟', n: 'love', k: 'fingers' },
            { c: '🤘', n: 'rock', k: 'fingers' },
            { c: '👈', n: 'point left', k: 'hand' },
            { c: '👉', n: 'point right', k: 'hand' },
            { c: '👆', n: 'point up', k: 'hand' },
            { c: '👇', n: 'point down', k: 'hand' },
            { c: '☝️', n: 'point up', k: 'hand one' },
            { c: '👍', n: 'thumbs up', k: 'hand like yes' },
            { c: '👎', n: 'thumbs down', k: 'hand dislike no' },
            { c: '✊', n: 'fist', k: 'hand' },
            { c: '👊', n: 'punch', k: 'fist' },
            { c: '🤛', n: 'fist left', k: 'hand' },
            { c: '🤜', n: 'fist right', k: 'hand' },
            { c: '👏', n: 'clap', k: 'hands applaud' },
            { c: '🙌', n: 'raising', k: 'hands celebrate' },
            { c: '👐', n: 'open hands', k: 'hands' },
            { c: '🤲', n: 'palms', k: 'hands together' },
            { c: '🤝', n: 'handshake', k: 'hands deal' },
            { c: '🙏', n: 'pray', k: 'hands thanks' },
            { c: '✍️', n: 'writing', k: 'hand write' },
            { c: '💪', n: 'muscle', k: 'arm strong' },
            { c: '🦾', n: 'mechanical arm', k: 'prosthetic' },
            { c: '🦿', n: 'mechanical leg', k: 'prosthetic' },
            { c: '🦵', n: 'leg', k: 'limb' },
            { c: '🦶', n: 'foot', k: 'limb' },
            { c: '👂', n: 'ear', k: 'listen' },
            { c: '👀', n: 'eyes', k: 'look' },
            { c: '👁️', n: 'eye', k: 'look' },
            { c: '🧠', n: 'brain', k: 'smart' },
            { c: '🗣️', n: 'speaking head', k: 'talk' },
            { c: '👤', n: 'bust', k: 'person silhouette' },
            { c: '👥', n: 'busts', k: 'people silhouettes' }
        ]
    },
    {
        id: 'animals', icon: '🐶', label: { ru: 'Животные', en: 'Animals' },
        items: [
            { c: '🐶', n: 'dog', k: 'pet' },
            { c: '🐱', n: 'cat', k: 'pet' },
            { c: '🐭', n: 'mouse', k: 'animal' },
            { c: '🐹', n: 'hamster', k: 'pet' },
            { c: '🐰', n: 'rabbit', k: 'bunny pet' },
            { c: '🦊', n: 'fox', k: 'animal' },
            { c: '🐻', n: 'bear', k: 'animal' },
            { c: '🐼', n: 'panda', k: 'animal' },
            { c: '🐨', n: 'koala', k: 'animal' },
            { c: '🐯', n: 'tiger', k: 'animal' },
            { c: '🦁', n: 'lion', k: 'animal' },
            { c: '🐮', n: 'cow', k: 'animal' },
            { c: '🐷', n: 'pig', k: 'animal' },
            { c: '🐸', n: 'frog', k: 'animal' },
            { c: '🐵', n: 'monkey', k: 'animal' },
            { c: '🐔', n: 'chicken', k: 'bird' },
            { c: '🐧', n: 'penguin', k: 'bird' },
            { c: '🐦', n: 'bird', k: 'animal' },
            { c: '🦆', n: 'duck', k: 'bird' },
            { c: '🦅', n: 'eagle', k: 'bird' },
            { c: '🦉', n: 'owl', k: 'bird' },
            { c: '🦇', n: 'bat', k: 'animal' },
            { c: '🐺', n: 'wolf', k: 'animal' },
            { c: '🐗', n: 'boar', k: 'animal' },
            { c: '🐴', n: 'horse', k: 'animal' },
            { c: '🦄', n: 'unicorn', k: 'magic' },
            { c: '🐝', n: 'bee', k: 'insect' },
            { c: '🐛', n: 'bug', k: 'insect' },
            { c: '🦋', n: 'butterfly', k: 'insect' },
            { c: '🐌', n: 'snail', k: 'animal' },
            { c: '🐞', n: 'ladybug', k: 'insect' },
            { c: '🐢', n: 'turtle', k: 'animal' },
            { c: '🐍', n: 'snake', k: 'reptile' },
            { c: '🦎', n: 'lizard', k: 'reptile' },
            { c: '🐙', n: 'octopus', k: 'sea' },
            { c: '🦑', n: 'squid', k: 'sea' },
            { c: '🦐', n: 'shrimp', k: 'sea' },
            { c: '🦀', n: 'crab', k: 'sea' },
            { c: '🐠', n: 'fish', k: 'tropical' },
            { c: '🐟', n: 'fish', k: 'animal' },
            { c: '🐬', n: 'dolphin', k: 'sea' },
            { c: '🐳', n: 'whale', k: 'sea' },
            { c: '🐋', n: 'whale', k: 'sea' },
            { c: '🦈', n: 'shark', k: 'sea' },
            { c: '🐊', n: 'crocodile', k: 'reptile' },
            { c: '🐅', n: 'tiger', k: 'animal' },
            { c: '🐆', n: 'leopard', k: 'animal' },
            { c: '🦓', n: 'zebra', k: 'animal' },
            { c: '🦒', n: 'giraffe', k: 'animal' },
            { c: '🐘', n: 'elephant', k: 'animal' },
            { c: '🦏', n: 'rhino', k: 'animal' },
            { c: '🐪', n: 'camel', k: 'animal' }
        ]
    },
    {
        id: 'food', icon: '🍔', label: { ru: 'Еда', en: 'Food' },
        items: [
            { c: '🍏', n: 'green apple', k: 'fruit' },
            { c: '🍎', n: 'apple', k: 'fruit' },
            { c: '🍐', n: 'pear', k: 'fruit' },
            { c: '🍊', n: 'orange', k: 'fruit' },
            { c: '🍋', n: 'lemon', k: 'fruit' },
            { c: '🍌', n: 'banana', k: 'fruit' },
            { c: '🍉', n: 'watermelon', k: 'fruit' },
            { c: '🍇', n: 'grapes', k: 'fruit' },
            { c: '🍓', n: 'strawberry', k: 'fruit' },
            { c: '🍈', n: 'melon', k: 'fruit' },
            { c: '🍒', n: 'cherries', k: 'fruit' },
            { c: '🍑', n: 'peach', k: 'fruit' },
            { c: '🥭', n: 'mango', k: 'fruit' },
            { c: '🍍', n: 'pineapple', k: 'fruit' },
            { c: '🥥', n: 'coconut', k: 'fruit' },
            { c: '🥝', n: 'kiwi', k: 'fruit' },
            { c: '🍅', n: 'tomato', k: 'vegetable' },
            { c: '🍆', n: 'eggplant', k: 'vegetable' },
            { c: '🥑', n: 'avocado', k: 'fruit' },
            { c: '🥦', n: 'broccoli', k: 'vegetable' },
            { c: '🥬', n: 'lettuce', k: 'leaf' },
            { c: '🥒', n: 'cucumber', k: 'vegetable' },
            { c: '🌶️', n: 'pepper', k: 'hot' },
            { c: '🌽', n: 'corn', k: 'vegetable' },
            { c: '🥕', n: 'carrot', k: 'vegetable' },
            { c: '🧄', n: 'garlic', k: 'vegetable' },
            { c: '🧅', n: 'onion', k: 'vegetable' },
            { c: '🥔', n: 'potato', k: 'vegetable' },
            { c: '🍠', n: 'sweet potato', k: 'vegetable' },
            { c: '🍞', n: 'bread', k: 'bakery' },
            { c: '🥐', n: 'croissant', k: 'bakery' },
            { c: '🧀', n: 'cheese', k: 'dairy' },
            { c: '🥚', n: 'egg', k: 'food' },
            { c: '🍳', n: 'fried egg', k: 'breakfast' },
            { c: '🥞', n: 'pancakes', k: 'breakfast' },
            { c: '🥓', n: 'bacon', k: 'meat' },
            { c: '🍔', n: 'burger', k: 'fast food' },
            { c: '🍟', n: 'fries', k: 'fast food' },
            { c: '🌭', n: 'hot dog', k: 'fast food' },
            { c: '🥪', n: 'sandwich', k: 'food' },
            { c: '🌮', n: 'taco', k: 'food' },
            { c: '🌯', n: 'burrito', k: 'food' },
            { c: '🍕', n: 'pizza', k: 'food' },
            { c: '🍜', n: 'noodles', k: 'ramen' },
            { c: '🍝', n: 'pasta', k: 'spaghetti' },
            { c: '🍣', n: 'sushi', k: 'food' },
            { c: '🍤', n: 'shrimp', k: 'food' },
            { c: '🍦', n: 'ice cream', k: 'dessert' },
            { c: '🍩', n: 'donut', k: 'dessert' },
            { c: '🍪', n: 'cookie', k: 'dessert' },
            { c: '🎂', n: 'birthday cake', k: 'dessert' },
            { c: '🍰', n: 'cake', k: 'dessert' },
            { c: '🍫', n: 'chocolate', k: 'dessert' },
            { c: '🍬', n: 'candy', k: 'sweet' },
            { c: '🍭', n: 'lollipop', k: 'sweet' },
            { c: '☕', n: 'coffee', k: 'drink' },
            { c: '🍵', n: 'tea', k: 'drink' },
            { c: '🥤', n: 'cup', k: 'drink soda' },
            { c: '🍺', n: 'beer', k: 'drink' },
            { c: '🍷', n: 'wine', k: 'drink' },
            { c: '🥂', n: 'clink', k: 'celebrate' }
        ]
    },
    {
        id: 'activities', icon: '⚽', label: { ru: 'Активности', en: 'Activities' },
        items: [
            { c: '⚽', n: 'soccer', k: 'sport ball' },
            { c: '🏀', n: 'basketball', k: 'sport ball' },
            { c: '🏈', n: 'football', k: 'sport ball' },
            { c: '⚾', n: 'baseball', k: 'sport ball' },
            { c: '🥎', n: 'softball', k: 'sport ball' },
            { c: '🎾', n: 'tennis', k: 'sport ball' },
            { c: '🏐', n: 'volleyball', k: 'sport ball' },
            { c: '🏉', n: 'rugby', k: 'sport ball' },
            { c: '🎱', n: 'pool', k: 'sport ball' },
            { c: '🏓', n: 'ping pong', k: 'sport' },
            { c: '🏸', n: 'badminton', k: 'sport' },
            { c: '🥅', n: 'goal', k: 'sport net' },
            { c: '🏒', n: 'hockey', k: 'sport' },
            { c: '🏑', n: 'field hockey', k: 'sport' },
            { c: '🥍', n: 'lacrosse', k: 'sport' },
            { c: '🏏', n: 'cricket', k: 'sport' },
            { c: '⛳', n: 'golf', k: 'sport' },
            { c: '🏹', n: 'bow', k: 'archery' },
            { c: '🎣', n: 'fishing', k: 'sport' },
            { c: '🥊', n: 'boxing', k: 'sport' },
            { c: '🥋', n: 'martial arts', k: 'sport' },
            { c: '⛸️', n: 'skate', k: 'ice' },
            { c: '🥌', n: 'curling', k: 'sport' },
            { c: '🛷', n: 'sled', k: 'winter' },
            { c: '🎿', n: 'ski', k: 'winter' },
            { c: '⛷️', n: 'skier', k: 'winter' },
            { c: '🏂', n: 'snowboard', k: 'winter' },
            { c: '🏋️', n: 'weight', k: 'gym' },
            { c: '🤼', n: 'wrestle', k: 'sport' },
            { c: '🤸', n: 'cartwheel', k: 'gymnastics' },
            { c: '⛹️', n: 'basketball', k: 'sport' },
            { c: '🤺', n: 'fencing', k: 'sport' },
            { c: '🤾', n: 'handball', k: 'sport' },
            { c: '🏌️', n: 'golf', k: 'sport' },
            { c: '🏇', n: 'horse', k: 'sport' },
            { c: '🧘', n: 'yoga', k: 'meditation' },
            { c: '🏆', n: 'trophy', k: 'prize' },
            { c: '🥇', n: 'gold', k: 'medal first' },
            { c: '🥈', n: 'silver', k: 'medal second' },
            { c: '🥉', n: 'bronze', k: 'medal third' },
            { c: '🏅', n: 'medal', k: 'sport' },
            { c: '🎖️', n: 'military', k: 'medal' },
            { c: '🎨', n: 'art', k: 'paint' },
            { c: '🎭', n: 'theatre', k: 'mask' },
            { c: '🎤', n: 'microphone', k: 'karaoke' },
            { c: '🎧', n: 'headphone', k: 'music' },
            { c: '🎼', n: 'music', k: 'score' },
            { c: '🎹', n: 'piano', k: 'music' },
            { c: '🥁', n: 'drum', k: 'music' },
            { c: '🎷', n: 'saxophone', k: 'music' },
            { c: '🎺', n: 'trumpet', k: 'music' },
            { c: '🎸', n: 'guitar', k: 'music' },
            { c: '🎻', n: 'violin', k: 'music' },
            { c: '🎲', n: 'dice', k: 'game' },
            { c: '🎯', n: 'dart', k: 'game' },
            { c: '🎳', n: 'bowling', k: 'game' },
            { c: '🎮', n: 'game', k: 'controller' },
            { c: '🎰', n: 'slot', k: 'casino' },
            { c: '🧩', n: 'puzzle', k: 'game' }
        ]
    },
    {
        id: 'travel', icon: '✈️', label: { ru: 'Путешествия', en: 'Travel' },
        items: [
            { c: '🚗', n: 'car', k: 'vehicle' },
            { c: '🚕', n: 'taxi', k: 'vehicle' },
            { c: '🚙', n: 'suv', k: 'vehicle' },
            { c: '🚌', n: 'bus', k: 'vehicle' },
            { c: '🚎', n: 'trolleybus', k: 'vehicle' },
            { c: '🏎️', n: 'racing', k: 'car' },
            { c: '🚓', n: 'police', k: 'car' },
            { c: '🚑', n: 'ambulance', k: 'car' },
            { c: '🚒', n: 'fire', k: 'truck' },
            { c: '🚐', n: 'minibus', k: 'van' },
            { c: '🚚', n: 'truck', k: 'delivery' },
            { c: '🚛', n: 'lorry', k: 'truck' },
            { c: '🚜', n: 'tractor', k: 'farm' },
            { c: '🛴', n: 'scooter', k: 'vehicle' },
            { c: '🚲', n: 'bike', k: 'bicycle' },
            { c: '🛵', n: 'scooter', k: 'motorcycle' },
            { c: '🏍️', n: 'motorcycle', k: 'vehicle' },
            { c: '🚨', n: 'light', k: 'police' },
            { c: '🚔', n: 'police', k: 'car' },
            { c: '🚍', n: 'bus', k: 'oncoming' },
            { c: '🚘', n: 'car', k: 'oncoming' },
            { c: '🚖', n: 'taxi', k: 'oncoming' },
            { c: '🚡', n: 'cable', k: 'tram' },
            { c: '🚠', n: 'mountain', k: 'cable' },
            { c: '🚟', n: 'suspension', k: 'railway' },
            { c: '🚃', n: 'railway', k: 'car' },
            { c: '🚋', n: 'tram', k: 'car' },
            { c: '🚞', n: 'mountain', k: 'railway' },
            { c: '🚝', n: 'monorail', k: 'train' },
            { c: '🚄', n: 'train', k: 'high speed' },
            { c: '🚅', n: 'train', k: 'bullet' },
            { c: '🚈', n: 'light', k: 'rail' },
            { c: '🚂', n: 'locomotive', k: 'train' },
            { c: '🚆', n: 'train', k: 'vehicle' },
            { c: '🚇', n: 'metro', k: 'subway' },
            { c: '🚊', n: 'tram', k: 'vehicle' },
            { c: '🚉', n: 'station', k: 'train' },
            { c: '✈️', n: 'airplane', k: 'flight' },
            { c: '🛫', n: 'takeoff', k: 'plane' },
            { c: '🛬', n: 'landing', k: 'plane' },
            { c: '🛩️', n: 'small plane', k: 'flight' },
            { c: '💺', n: 'seat', k: 'plane' },
            { c: '🚁', n: 'helicopter', k: 'flight' },
            { c: '🚟', n: 'suspension', k: 'railway' },
            { c: '🚤', n: 'boat', k: 'speed' },
            { c: '⛵', n: 'sailboat', k: 'sea' },
            { c: '🛳️', n: 'passenger', k: 'ship' },
            { c: '⛴️', n: 'ferry', k: 'boat' },
            { c: '🛥️', n: 'motor boat', k: 'boat' },
            { c: '🚢', n: 'ship', k: 'sea' },
            { c: '⚓', n: 'anchor', k: 'sea' },
            { c: '🗺️', n: 'map', k: 'world' },
            { c: '🧭', n: 'compass', k: 'navigation' },
            { c: '🏔️', n: 'mountain', k: 'snow' },
            { c: '⛰️', n: 'mountain', k: 'rock' },
            { c: '🌋', n: 'volcano', k: 'mountain' },
            { c: '🏕️', n: 'camping', k: 'tent' },
            { c: '🏖️', n: 'beach', k: 'sea' },
            { c: '🏜️', n: 'desert', k: 'sand' },
            { c: '🏝️', n: 'island', k: 'sea' },
            { c: '🏞️', n: 'park', k: 'nature' },
            { c: '🏟️', n: 'stadium', k: 'sport' },
            { c: '🏛️', n: 'building', k: 'classic' },
            { c: '🏗️', n: 'construction', k: 'crane' },
            { c: '🏘️', n: 'houses', k: 'city' },
            { c: '🏚️', n: 'derelict', k: 'house' },
            { c: '🏠', n: 'house', k: 'home' },
            { c: '🏡', n: 'garden', k: 'house' },
            { c: '-office', n: 'office', k: 'building' },
            { c: '🏢', n: 'office', k: 'building' },
            { c: '🏣', n: 'post', k: 'office' },
            { c: '🏤', n: 'post', k: 'european' },
            { c: '🏥', n: 'hospital', k: 'medical' },
            { c: '🏦', n: 'bank', k: 'building' },
            { c: '🏨', n: 'hotel', k: 'building' },
            { c: '🏪', n: 'store', k: 'shop' },
            { c: '🏫', n: 'school', k: 'building' },
            { c: '🏬', n: 'mall', k: 'department store' },
            { c: '🏭', n: 'factory', k: 'industrial' }
        ]
    },
    {
        id: 'objects', icon: '💡', label: { ru: 'Объекты', en: 'Objects' },
        items: [
            { c: '⌚', n: 'watch', k: 'time' },
            { c: '📱', n: 'phone', k: 'mobile' },
            { c: '💻', n: 'laptop', k: 'computer' },
            { c: '🖥️', n: 'desktop', k: 'computer' },
            { c: '🖨️', n: 'printer', k: 'office' },
            { c: '⌨️', n: 'keyboard', k: 'computer' },
            { c: '🖱️', n: 'mouse', k: 'computer' },
            { c: '💿', n: 'cd', k: 'disk' },
            { c: '💾', n: 'floppy', k: 'save' },
            { c: '💽', n: 'minidisc', k: 'data' },
            { c: '📀', n: 'dvd', k: 'disk' },
            { c: '📷', n: 'camera', k: 'photo' },
            { c: '📸', n: 'flash', k: 'camera' },
            { c: '📹', n: 'video', k: 'camera' },
            { c: '🎥', n: 'movie', k: 'camera' },
            { c: '📽️', n: 'projector', k: 'film' },
            { c: '🎞️', n: 'film', k: 'movie' },
            { c: '📞', n: 'phone', k: 'call' },
            { c: '☎️', n: 'phone', k: 'call' },
            { c: '📟', n: 'pager', k: 'call' },
            { c: '📠', n: 'fax', k: 'office' },
            { c: '📺', n: 'tv', k: 'television' },
            { c: '📻', n: 'radio', k: 'audio' },
            { c: '🎙️', n: 'studio', k: 'microphone' },
            { c: '⏰', n: 'alarm', k: 'clock' },
            { c: '⏲️', n: 'timer', k: 'clock' },
            { c: '⏱️', n: 'stopwatch', k: 'clock' },
            { c: '🕐', n: 'one', k: 'clock' },
            { c: '🕑', n: 'two', k: 'clock' },
            { c: '🕒', n: 'three', k: 'clock' },
            { c: '🕓', n: 'four', k: 'clock' },
            { c: '🕔', n: 'five', k: 'clock' },
            { c: '🕕', n: 'six', k: 'clock' },
            { c: '🕖', n: 'seven', k: 'clock' },
            { c: '🕗', n: 'eight', k: 'clock' },
            { c: '🕘', n: 'nine', k: 'clock' },
            { c: '🕙', n: 'ten', k: 'clock' },
            { c: '🕚', n: 'eleven', k: 'clock' },
            { c: '🕛', n: 'twelve', k: 'clock' },
            { c: '🔋', n: 'battery', k: 'power' },
            { c: '🔌', n: 'plug', k: 'power' },
            { c: '💡', n: 'bulb', k: 'idea light' },
            { c: '🔦', n: 'flashlight', k: 'light' },
            { c: '🕯️', n: 'candle', k: 'light' },
            { c: '🧯', n: 'extinguisher', k: 'fire' },
            { c: '🛢️', n: 'oil', k: 'drum' },
            { c: '💸', n: 'money', k: 'cash' },
            { c: '💵', n: 'dollar', k: 'money' },
            { c: '💴', n: 'yen', k: 'money' },
            { c: '💶', n: 'euro', k: 'money' },
            { c: '💷', n: 'pound', k: 'money' },
            { c: '💰', n: 'bag', k: 'money' },
            { c: '💳', n: 'card', k: 'credit' },
            { c: '💎', n: 'gem', k: 'diamond' },
            { c: '⚖️', n: 'scales', k: 'balance' },
            { c: '🔧', n: 'wrench', k: 'tool' },
            { c: '🔨', n: 'hammer', k: 'tool' },
            { c: '🛠️', n: 'tools', k: 'hammer wrench' },
            { c: '⛏️', n: 'pick', k: 'mining' },
            { c: '🔩', n: 'nut', k: 'bolt tool' },
            { c: '⚙️', n: 'gear', k: 'settings' },
            { c: '🧰', n: 'toolbox', k: 'tool' },
            { c: '🧲', n: 'magnet', k: 'attract' },
            { c: '🔫', n: 'pistol', k: 'water gun' },
            { c: '💣', n: 'bomb', k: 'explosion' },
            { c: '🧨', n: 'firecracker', k: 'explosive' },
            { c: '🪓', n: 'axe', k: 'tool' },
            { c: '🔪', n: 'knife', k: 'kitchen' },
            { c: '🗡️', n: 'dagger', k: 'weapon' },
            { c: '⚔️', n: 'swords', k: 'crossed' },
            { c: '🛡️', n: 'shield', k: 'protection' },
            { c: '🚬', n: 'smoking', k: 'cigarette' },
            { c: '⚰️', n: 'coffin', k: 'death' },
            { c: '⚱️', n: 'urn', k: 'funeral' },
            { c: '🏺', n: 'amphora', k: 'vase' }
        ]
    },
    {
        id: 'symbols', icon: '❤️', label: { ru: 'Символы', en: 'Symbols' },
        items: [
            { c: '❤️', n: 'heart', k: 'love' },
            { c: '🧡', n: 'orange heart', k: 'love' },
            { c: '💛', n: 'yellow heart', k: 'love' },
            { c: '💚', n: 'green heart', k: 'love' },
            { c: '💙', n: 'blue heart', k: 'love' },
            { c: '💜', n: 'purple heart', k: 'love' },
            { c: '🤎', n: 'brown heart', k: 'love' },
            { c: '🖤', n: 'black heart', k: 'love' },
            { c: '🤍', n: 'white heart', k: 'love' },
            { c: '💔', n: 'broken heart', k: 'sad' },
            { c: '❣️', n: 'heart exclamation', k: 'love' },
            { c: '💕', n: 'two hearts', k: 'love' },
            { c: '💞', n: 'revolving', k: 'love' },
            { c: '💓', n: 'beating', k: 'love' },
            { c: '💗', n: 'growing', k: 'love' },
            { c: '💖', n: 'sparkling', k: 'love' },
            { c: '💘', n: 'arrow', k: 'cupid love' },
            { c: '💝', n: 'ribbon', k: 'gift love' },
            { c: '⚛️', n: 'atom', k: 'science' },
            { c: '🕉️', n: 'om', k: 'religion' },
            { c: '✡️', n: 'star of david', k: 'religion' },
            { c: '☸️', n: 'wheel of dharma', k: 'religion' },
            { c: '☯️', n: 'yin yang', k: 'balance' },
            { c: '✝️', n: 'cross', k: 'religion' },
            { c: '☦️', n: 'orthodox', k: 'cross' },
            { c: '☪️', n: 'star and crescent', k: 'religion' },
            { c: '☮️', n: 'peace', k: 'symbol' },
            { c: '🕎', n: 'menorah', k: 'religion' },
            { c: '🔯', n: 'six pointed', k: 'star' },
            { c: '♻️', n: 'recycle', k: 'environment' },
            { c: '✡️', n: 'david', k: 'religion' },
            { c: '💠', n: 'diamond', k: 'shape' },
            { c: '🔶', n: 'orange diamond', k: 'shape' },
            { c: '🔷', n: 'blue diamond', k: 'shape' },
            { c: '🔹', n: 'small blue', k: 'shape' },
            { c: '🔺', n: 'red triangle', k: 'shape' },
            { c: '🔻', n: 'red triangle', k: 'down shape' },
            { c: '⭕', n: 'circle', k: 'red' },
            { c: '✅', n: 'check', k: 'green mark' },
            { c: '☑️', n: 'check box', k: 'mark' },
            { c: '✔️', n: 'check', k: 'mark' },
            { c: '❌', n: 'cross', k: 'red mark' },
            { c: '❎', n: 'cross mark', k: 'no' },
            { c: '➕', n: 'plus', k: 'math' },
            { c: '➖', n: 'minus', k: 'math' },
            { c: '➗', n: 'divide', k: 'math' },
            { c: '✖️', n: 'multiply', k: 'math' },
            { c: '🟰', n: 'equal', k: 'math' },
            { c: '♾️', n: 'infinity', k: 'math' },
            { c: '‼️', n: 'exclamation', k: 'double' },
            { c: '⁉️', n: 'interrobang', k: 'question' },
            { c: '❓', n: 'question', k: 'mark' },
            { c: '❕', n: 'exclamation', k: 'mark' },
            { c: '❗', n: 'exclamation', k: 'mark' },
            { c: '〰️', n: 'wave', k: 'dash' },
            { c: '💱', n: 'exchange', k: 'currency' },
            { c: '💲', n: 'dollar', k: 'money' },
            { c: '⚕️', n: 'medical', k: 'symbol' },
            { c: '🆎', n: 'ab', k: 'blood' },
            { c: '🆑', n: 'cl', k: 'button' },
            { c: '🆒', n: 'cool', k: 'button' },
            { c: '🆓', n: 'free', k: 'button' },
            { c: '🆔', n: 'id', k: 'button' },
            { c: '🆕', n: 'new', k: 'button' },
            { c: '🆖', n: 'ng', k: 'button' },
            { c: '🆗', n: 'ok', k: 'button' },
            { c: '🆙', n: 'up', k: 'button' },
            { c: '🆚', n: 'vs', k: 'button' },
            { c: '🈁', n: 'here', k: 'japanese' },
            { c: '🈶', n: 'not free', k: 'japanese' },
            { c: '🈚', n: 'free', k: 'japanese' },
            { c: '🈯', n: 'reserved', k: 'japanese' },
            { c: '🈷️', n: 'monthly', k: 'amount' },
            { c: '🈸', n: 'apply', k: 'japanese' },
            { c: '🈹', n: 'divide', k: 'japanese' },
            { c: '🈺', n: 'open', k: 'business' },
            { c: '🉑', n: 'accept', k: 'japanese' },
            { c: '🔱', n: 'trident', k: 'emblem' },
            { c: '📛', n: 'badge', k: 'name' },
            { c: '🔰', n: 'beginner', k: 'japanese' },
            { c: '⭕', n: 'circle', k: 'large' },
            { c: '✅', n: 'check', k: 'button' },
            { c: '☑️', n: 'check', k: 'box' },
            { c: '✔️', n: 'check', k: 'mark' },
            { c: '❌', n: 'cross', k: 'mark' },
            { c: '❎', n: 'cross', k: 'button' },
            { c: '➰', n: 'loop', k: 'curly' },
            { c: '➿', n: 'double loop', k: 'curly' },
            { c: '〽️', n: 'part alternation', k: 'mark' },
            { c: '✳️', n: 'asterisk', k: 'eight spoke' },
            { c: '✴️', n: 'star', k: 'eight pointed' },
            { c: '❇️', n: 'sparkle', k: 'symbol' },
            { c: '©️', n: 'copyright', k: 'symbol' },
            { c: '®️', n: 'registered', k: 'symbol' },
            { c: '™️', n: 'trademark', k: 'symbol' },
            { c: '🔝', n: 'top', k: 'button' },
            { c: '🔚', n: 'end', k: 'button' },
            { c: '🔙', n: 'back', k: 'button' },
            { c: '🔛', n: 'on', k: 'button' },
            { c: '🔜', n: 'soon', k: 'button' },
            { c: '🔰', n: 'japanese', k: 'beginner' },
            { c: '⏫', n: 'fast up', k: 'button' },
            { c: '⏬', n: 'fast down', k: 'button' },
            { c: '⏪', n: 'rewind', k: 'button' },
            { c: '⏩', n: 'fast forward', k: 'button' },
            { c: '⏭️', n: 'next', k: 'track' },
            { c: '⏮️', n: 'previous', k: 'track' },
            { c: '🔼', n: 'up', k: 'button' },
            { c: '🔽', n: 'down', k: 'button' }
        ]
    },
    {
        id: 'flags', icon: '🏁', label: { ru: 'Флаги', en: 'Flags' },
        items: [
            { c: '🏁', n: 'checkered', k: 'flag race' },
            { c: '🚩', n: 'triangular', k: 'flag red' },
            { c: '🎌', n: 'crossed', k: 'flag' },
            { c: '🏴', n: 'black', k: 'flag' },
            { c: '🏳️', n: 'white', k: 'flag' },
            { c: '🏳️‍🌈', n: 'rainbow', k: 'pride flag' },
            { c: '🏳️‍⚧️', n: 'transgender', k: 'flag' },
            { c: '🏴‍☠️', n: 'pirate', k: 'flag' },
            { c: '🇷🇺', n: 'russia', k: 'flag country' },
            { c: '🇺🇸', n: 'usa', k: 'flag country' },
            { c: '🇬🇧', n: 'uk', k: 'flag country' },
            { c: '🇪🇺', n: 'europe', k: 'flag' },
            { c: '🇨🇳', n: 'china', k: 'flag country' },
            { c: '🇯🇵', n: 'japan', k: 'flag country' },
            { c: '🇰🇷', n: 'korea', k: 'flag country' },
            { c: '🇩🇪', n: 'germany', k: 'flag country' },
            { c: '🇫🇷', n: 'france', k: 'flag country' },
            { c: '🇮🇹', n: 'italy', k: 'flag country' },
            { c: '🇪🇸', n: 'spain', k: 'flag country' },
            { c: '🇵🇹', n: 'portugal', k: 'flag country' },
            { c: '🇳🇱', n: 'netherlands', k: 'flag country' },
            { c: '🇧🇪', n: 'belgium', k: 'flag country' },
            { c: '🇨🇦', n: 'canada', k: 'flag country' },
            { c: '🇧🇷', n: 'brazil', k: 'flag country' },
            { c: '🇦🇷', n: 'argentina', k: 'flag country' },
            { c: '🇲🇽', n: 'mexico', k: 'flag country' },
            { c: '🇮🇳', n: 'india', k: 'flag country' },
            { c: '🇸🇦', n: 'saudi arabia', k: 'flag country' },
            { c: '🇦🇪', n: 'uae', k: 'flag country' },
            { c: '🇮🇱', n: 'israel', k: 'flag country' },
            { c: '🇹🇷', n: 'turkey', k: 'flag country' },
            { c: '🇪🇬', n: 'egypt', k: 'flag country' },
            { c: '🇿🇦', n: 'south africa', k: 'flag country' },
            { c: '🇦🇺', n: 'australia', k: 'flag country' },
            { c: '🇳🇿', n: 'new zealand', k: 'flag country' },
            { c: '🇸🇪', n: 'sweden', k: 'flag country' },
            { c: '🇳🇴', n: 'norway', k: 'flag country' },
            { c: '🇫🇮', n: 'finland', k: 'flag country' },
            { c: '🇩🇰', n: 'denmark', k: 'flag country' },
            { c: '🇵🇱', n: 'poland', k: 'flag country' },
            { c: '🇨🇿', n: 'czech', k: 'flag country' },
            { c: '🇭🇺', n: 'hungary', k: 'flag country' },
            { c: '🇷🇴', n: 'romania', k: 'flag country' },
            { c: '🇧🇬', n: 'bulgaria', k: 'flag country' },
            { c: '🇬🇷', n: 'greece', k: 'flag country' },
            { c: '🇺🇦', n: 'ukraine', k: 'flag country' },
            { c: '🇧🇾', n: 'belarus', k: 'flag country' },
            { c: '🇰🇿', n: 'kazakhstan', k: 'flag country' },
            { c: '🇺🇿', n: 'uzbekistan', k: 'flag country' },
            { c: '🇬🇪', n: 'georgia', k: 'flag country' },
            { c: '🇦🇲', n: 'armenia', k: 'flag country' },
            { c: '🇦🇿', n: 'azerbaijan', k: 'flag country' }
        ]
    }
];

function loadRecent() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const arr = JSON.parse(raw);
        return Array.isArray(arr) ? arr.filter(s => typeof s === 'string') : [];
    } catch (e) { return []; }
}

function saveRecent(arr) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(arr.slice(0, MAX_RECENT))); }
    catch (e) {}
}

function pushRecent(emoji) {
    let arr = loadRecent();
    arr = arr.filter(e => e !== emoji);
    arr.unshift(emoji);
    saveRecent(arr);
}

/**
 * Open the advanced emoji picker overlay.
 * @param {object} editor — LuminaEditor instance
 */
export function openEmojiPicker(editor) {
    const lang = (typeof editor.t === 'function') ? (editor._currentLang || 'ru') : 'ru';
    const t = (typeof editor.t === 'function') ? editor.t.bind(editor) : (k => k);

    // Remove any existing picker
    const existing = document.querySelector('.lumina-emoji-overlay');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.className = 'lumina-emoji-overlay';
    overlay.style.cssText = `
        position: fixed; inset: 0; background: rgba(0,0,0,0.45);
        display: flex; align-items: center; justify-content: center;
        z-index: 100000; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    `;

    const picker = document.createElement('div');
    picker.className = 'lumina-emoji-picker';
    picker.style.cssText = `
        background: #fff; border-radius: 12px; width: 420px; max-width: 92vw;
        max-height: 80vh; display: flex; flex-direction: column; overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    `;

    // Header — title + close
    const header = document.createElement('div');
    header.style.cssText = `
        padding: 12px 16px; border-bottom: 1px solid #e5e7eb;
        display: flex; align-items: center; justify-content: space-between;
    `;
    header.innerHTML = `
        <span style="font-weight:600; color:#111827; font-size:15px;">${t('emoji') || 'Emoji'}</span>
        <button type="button" class="lumina-emoji-close" style="background:none;border:none;cursor:pointer;font-size:20px;color:#6b7280;padding:0 4px;line-height:1;">×</button>
    `;

    // Search box
    const searchWrap = document.createElement('div');
    searchWrap.style.cssText = `padding: 8px 12px; border-bottom: 1px solid #f3f4f6;`;
    searchWrap.innerHTML = `
        <input type="text" class="lumina-emoji-search" placeholder="${t('emoji_search_ph') || 'Search emoji...'}"
               style="width:100%; padding:8px 10px; border:1px solid #e5e7eb; border-radius:8px; outline:none; font-size:14px;">
    `;

    // Recently used row
    const recentWrap = document.createElement('div');
    recentWrap.className = 'lumina-emoji-recent';
    recentWrap.style.cssText = `padding: 8px 12px; border-bottom: 1px solid #f3f4f6; min-height: 44px;`;

    // Category tabs
    const tabs = document.createElement('div');
    tabs.className = 'lumina-emoji-tabs';
    tabs.style.cssText = `
        display: flex; padding: 4px 8px; border-bottom: 1px solid #f3f4f6;
        overflow-x: auto; gap: 2px;
    `;

    // Grid container (scrollable)
    const gridWrap = document.createElement('div');
    gridWrap.className = 'lumina-emoji-grid-wrap';
    gridWrap.style.cssText = `flex: 1; overflow-y: auto; padding: 8px; min-height: 280px; max-height: 50vh;`;

    // Build category tabs
    let activeCat = EMOJI_CATALOG[0].id;
    EMOJI_CATALOG.forEach(cat => {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.dataset.cat = cat.id;
        tab.title = cat.label[lang] || cat.label.en;
        tab.textContent = cat.icon;
        tab.style.cssText = `
            background: none; border: none; cursor: pointer; font-size: 18px;
            padding: 6px 10px; border-radius: 6px; transition: background 0.15s;
        `;
        tab.onmouseenter = () => { if (tab.dataset.active !== '1') tab.style.background = '#f3f4f6'; };
        tab.onmouseleave = () => { if (tab.dataset.active !== '1') tab.style.background = 'transparent'; };
        tab.onclick = () => {
            activeCat = cat.id;
            updateActiveTab();
            renderGrid();
        };
        tabs.appendChild(tab);
    });

    function updateActiveTab() {
        tabs.querySelectorAll('button').forEach(b => {
            if (b.dataset.cat === activeCat) {
                b.dataset.active = '1';
                b.style.background = '#e0e7ff';
                b.style.color = '#1d4ed8';
            } else {
                b.dataset.active = '0';
                b.style.background = 'transparent';
                b.style.color = '#374151';
            }
        });
    }

    function renderGrid() {
        const search = searchWrap.querySelector('input').value.trim().toLowerCase();
        gridWrap.innerHTML = '';

        let itemsToShow = [];
        if (search) {
            // Search across all categories
            EMOJI_CATALOG.forEach(cat => {
                cat.items.forEach(it => {
                    if (it.n.toLowerCase().includes(search) || it.k.toLowerCase().includes(search)) {
                        itemsToShow.push(it);
                    }
                });
            });
            if (itemsToShow.length === 0) {
                gridWrap.innerHTML = `<div style="text-align:center;padding:40px 12px;color:#9ca3af;font-size:14px;">${t('emoji_no_results') || 'No results'}</div>`;
                return;
            }
        } else {
            const cat = EMOJI_CATALOG.find(c => c.id === activeCat);
            itemsToShow = cat ? cat.items : [];
        }

        const grid = document.createElement('div');
        grid.style.cssText = `
            display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px;
        `;
        itemsToShow.forEach(it => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = it.c;
            btn.title = it.n;
            btn.style.cssText = `
                background: none; border: none; cursor: pointer; font-size: 22px;
                padding: 6px 4px; border-radius: 6px; line-height: 1; transition: background 0.1s;
                aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
            `;
            btn.onmouseenter = () => { btn.style.background = '#f3f4f6'; };
            btn.onmouseleave = () => { btn.style.background = 'transparent'; };
            btn.onclick = () => {
                insertEmoji(it.c);
                pushRecent(it.c);
                renderRecent();
            };
            grid.appendChild(btn);
        });
        gridWrap.appendChild(grid);
    }

    function renderRecent() {
        const recent = loadRecent();
        recentWrap.innerHTML = '';
        if (recent.length === 0) {
            recentWrap.innerHTML = `<span style="color:#9ca3af; font-size:12px; padding:8px 4px;">${t('emoji_no_recent') || 'No recently used'}</span>`;
            return;
        }
        const label = document.createElement('span');
        label.style.cssText = `color:#6b7280; font-size:11px; margin-right:8px; align-self:center;`;
        label.textContent = (t('emoji_recent') || 'Recent') + ':';
        recentWrap.appendChild(label);
        const row = document.createElement('div');
        row.style.cssText = `display:flex; flex-wrap:wrap; gap:2px; flex:1;`;
        recent.slice(0, 12).forEach(emoji => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = emoji;
            btn.style.cssText = `
                background:none; border:none; cursor:pointer; font-size:20px;
                padding:2px 4px; border-radius:4px; line-height:1;
            `;
            btn.onmouseenter = () => { btn.style.background = '#f3f4f6'; };
            btn.onmouseleave = () => { btn.style.background = 'transparent'; };
            btn.onclick = () => insertEmoji(emoji);
            row.appendChild(btn);
        });
        recentWrap.appendChild(row);
    }

    function insertEmoji(emoji) {
        editor.editor.focus();
        editor.restoreSelection();
        document.execCommand('insertText', false, emoji);
        editor.pushHistory(true);
        editor.sync();
    }

    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }

    function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); }
        // Allow typing in search without bubbling to editor
        if (e.key !== 'Escape') e.stopPropagation();
    }

    // Assemble picker
    picker.appendChild(header);
    picker.appendChild(searchWrap);
    picker.appendChild(recentWrap);
    picker.appendChild(tabs);
    picker.appendChild(gridWrap);
    overlay.appendChild(picker);
    document.body.appendChild(overlay);

    // Wire events
    header.querySelector('.lumina-emoji-close').onclick = close;
    overlay.onclick = (e) => { if (e.target === overlay) close(); };
    searchWrap.querySelector('input').oninput = () => renderGrid();
    searchWrap.querySelector('input').onkeydown = (e) => { e.stopPropagation(); if (e.key === 'Enter') { e.preventDefault(); const first = gridWrap.querySelector('button'); if (first) first.click(); } };

    // Initial render
    updateActiveTab();
    renderGrid();
    renderRecent();

    // Focus search input
    setTimeout(() => searchWrap.querySelector('input').focus(), 50);

    document.addEventListener('keydown', onKey);
}

export default { openEmojiPicker, EMOJI_CATALOG };
